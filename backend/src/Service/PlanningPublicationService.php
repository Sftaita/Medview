<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Planning;
use App\Entity\PlanningCycle;
use App\Entity\PlanningLine;
use App\Entity\PlanningPeriodStatus;
use App\Entity\PlanningPublication;
use App\Entity\PlanningPublicationDelivery;
use App\Entity\PlanningPublicationEntry;
use App\Entity\PlanningPublicationKind;
use App\Entity\PlanningPublicationLine;
use App\Entity\PlanningPublicationLineKind;
use App\Entity\PlanningPublicationNotification;
use App\Entity\PublicationNotificationStatus;
use App\Entity\User;
use App\Exception\NoUnpublishedChangesException;
use App\Exception\PlanningAlreadyPublishedException;
use App\Exception\PlanningNotPublishableException;
use App\Exception\PlanningNotYetPublishedException;
use App\Exception\PlanningPublicationInProgressException;
use App\Exception\PublicationLineSelectionException;
use App\Repository\PlanningLineRepository;
use App\Repository\PlanningPublicationDeliveryRepository;
use App\Repository\PlanningPublicationNotificationRepository;
use App\Repository\PlanningPublicationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * "Publier le planning" and "Republier les modifications"
 * (docs/decisions.md D133, D143), one period at a time since D196 (lot L4c)
 * — a facade over the per-line `PlanningPeriodStatus` lifecycle, the same
 * shape as generation (D129): publishing lines means transitioning their
 * period, always through `PlanningPeriodLifecycleService::transition()` —
 * including the GENERATED → VALIDATED step, a purely technical transition
 * never exposed to the user.
 *
 * Two operations, never guessed from the state:
 *
 * - publish: the first publication of lines in the period. Without a list,
 *   all or nothing (the historical behaviour): every line that counts must
 *   be ready, nothing may be published yet. With an explicit list, those
 *   lines only (FIRST_FOR_LINE), the others left exactly as they are —
 *   including in a period already published, as long as its published lines
 *   have no change waiting (republish then, choosing the new lines with it);
 * - republish: the changes of the period's published lines (UPDATED, only
 *   those whose published content changed), plus the new lines explicitly
 *   chosen with it — never a line nobody chose.
 *
 * Every diffusion is recorded (PlanningPublication, one
 * PlanningPublicationEntry per Duty of every published line — exactly what
 * the period's calendar now announces — and one PlanningPublicationLine per
 * line it concerned), in one transaction with the status transitions,
 * after the preflight is run again under the CalendarWriteLock: what is
 * checked is what is recorded, no reassignment or generation can slip in
 * between. That record is the reference "Modifications non publiées" is
 * computed against; each publication becomes the period's new reference.
 * Other periods are never read as references nor written.
 *
 * The emails a diffusion owes are recorded in that same transaction
 * (PlanningPublicationNotification, docs/decisions.md D172: one per person —
 * the lines first published for them, and their own changes), then sent
 * after the commit by PublicationNotificationSender — a mail failure never
 * undoes a publication, and is retried, never lost.
 *
 * Publication is never a lock: the calendar stays editable afterwards
 * (D133) and a later edit sends nothing by itself — only an explicit
 * republication does.
 */
final class PlanningPublicationService
{
    private const ADVISORY_LOCK_NAMESPACE = 7353;

    public function __construct(
        private readonly PlanningLineRepository $lineRepository,
        private readonly PlanningPublicationRepository $publicationRepository,
        private readonly PlanningPublicationPreflightService $preflightService,
        private readonly PlanningPeriodLifecycleService $lifecycleService,
        private readonly CurrentCalendarReader $calendarReader,
        private readonly PublicationChangeService $changeService,
        private readonly PlanningPdfRenderer $pdfRenderer,
        private readonly PublicationNotificationSender $notificationSender,
        private readonly PlanningPublicationNotificationRepository $notificationRepository,
        private readonly PlanningPublicationDeliveryRepository $deliveryRepository,
        private readonly CalendarWriteLock $calendarWriteLock,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * The first publication of lines in $cycle (omitted: the planning's only
     * period). $lineStableIds null: all or nothing; a list: those lines only.
     *
     * @param list<string>|null $lineStableIds
     *
     * @throws PlanningPublicationInProgressException
     * @throws PlanningAlreadyPublishedException
     * @throws PlanningNotPublishableException
     * @throws PublicationLineSelectionException
     */
    public function publish(Planning $planning, User $publishedBy, ?PlanningCycle $cycle = null, ?array $lineStableIds = null): PublicationOutcome
    {
        $cycle ??= $planning->getOnlyCycle();

        return $this->locked($planning, fn (): PublicationOutcome => $this->record($planning, $cycle, $publishedBy, function (PublicationPreflight $preflight) use ($planning, $cycle, $lineStableIds): array {
            $reference = $this->publicationRepository->findLatestForCycle($cycle);
            if (null === $lineStableIds) {
                if (null !== $reference || [] !== $preflight->publishedLines()) {
                    throw new PlanningAlreadyPublishedException();
                }
                // Always the real preflight, under the lock — never one the client loaded earlier.
                if (!$preflight->publishable) {
                    throw new PlanningNotPublishableException($preflight);
                }

                return [$reference, array_map(static fn (PublicationLineReadiness $line): PlanningLine => $line->line, $preflight->lines), false];
            }

            $firstLines = $this->selectNewLines($preflight, $lineStableIds);
            if (null !== $reference && [] !== $this->changesOnPublishedLines($planning, $cycle, $reference, $preflight)) {
                // Publishing new lines alone would freeze the published lines' pending changes without telling anyone.
                throw PublicationLineSelectionException::republishRequired(array_map(static fn (PublicationLineReadiness $line): PlanningLine => $line->line, $preflight->publishedLines()));
            }

            return [$reference, $firstLines, false];
        }));
    }

    /**
     * The changes of $cycle's published lines since its last publication
     * (docs/decisions.md D172: only the people whose own duties changed are
     * emailed), plus the new lines explicitly chosen with them (D196).
     *
     * @param list<string> $newLineStableIds
     *
     * @throws PlanningPublicationInProgressException
     * @throws PlanningNotYetPublishedException
     * @throws NoUnpublishedChangesException
     * @throws PlanningNotPublishableException
     * @throws PublicationLineSelectionException
     */
    public function republish(Planning $planning, User $publishedBy, ?PlanningCycle $cycle = null, array $newLineStableIds = []): PublicationOutcome
    {
        $cycle ??= $planning->getOnlyCycle();

        return $this->locked($planning, fn (): PublicationOutcome => $this->record($planning, $cycle, $publishedBy, function (PublicationPreflight $preflight) use ($planning, $cycle, $newLineStableIds): array {
            $reference = $this->publicationRepository->findLatestForCycle($cycle);
            if (null === $reference) {
                throw new PlanningNotYetPublishedException();
            }
            $firstLines = [] !== $newLineStableIds ? $this->selectNewLines($preflight, $newLineStableIds) : [];
            if ([] === $firstLines && [] === $this->changesOnPublishedLines($planning, $cycle, $reference, $preflight)) {
                throw new NoUnpublishedChangesException();
            }
            if (!$preflight->republishable) {
                throw new PlanningNotPublishableException($preflight);
            }

            return [$reference, $firstLines, true];
        }));
    }

    /**
     * The state the calendar screen shows for $cycle (docs/decisions.md D143, D196).
     */
    public function state(Planning $planning, ?PlanningCycle $cycle = null): PublicationState
    {
        $cycle ??= $planning->getOnlyCycle();
        $history = $this->publicationRepository->findByCycle($cycle);
        $awaiting = array_values(array_filter(
            $this->lineRepository->findByPlanning($planning),
            static fn (PlanningLine $line): bool => $line->isActive()
                && \in_array($line->periodIn($cycle)?->getStatus(), [PlanningPeriodStatus::GENERATED, PlanningPeriodStatus::VALIDATED], true),
        ));
        $latest = $this->publicationRepository->findLatestForCycle($cycle);
        if (null === $latest) {
            return new PublicationState(null, null, [], $history, $cycle, $awaiting);
        }

        $changes = $this->changeService->changesSince($latest, $this->calendarReader->read($planning, $this->publishedIn($cycle), $cycle));

        return new PublicationState($this->publicationRepository->findFirstForCycle($cycle), $latest, $changes, $history, $cycle, $awaiting);
    }

    /**
     * Who a diffusion had to tell and how it went (docs/decisions.md D172):
     * failedCount = still to be retried or given up — never hidden. A
     * publication recorded before D172 only has its append-only
     * PlanningPublicationDelivery audit (nothing to retry there).
     *
     * @return array{recipientCount: int, sentCount: int, failedCount: int}
     */
    public function deliveryCounts(PlanningPublication $publication): array
    {
        $notifications = $this->notificationRepository->findByPublication($publication);
        if ([] !== $notifications) {
            $count = static fn (PublicationNotificationStatus ...$statuses): int => \count(array_filter($notifications, static fn (PlanningPublicationNotification $n): bool => \in_array($n->getStatus(), $statuses, true)));

            return [
                'recipientCount' => \count(array_filter($notifications, static fn (PlanningPublicationNotification $n): bool => PublicationNotificationStatus::CANCELLED !== $n->getStatus())),
                'sentCount' => $count(PublicationNotificationStatus::SENT),
                'failedCount' => $count(PublicationNotificationStatus::FAILED),
            ];
        }

        $deliveries = $this->deliveryRepository->findByPublication($publication);
        $sent = \count(array_filter($deliveries, static fn (PlanningPublicationDelivery $delivery): bool => $delivery->isSent()));

        return ['recipientCount' => \count($deliveries), 'sentCount' => $sent, 'failedCount' => \count($deliveries) - $sent];
    }

    /**
     * The chosen new lines (D196): each must count in the period, be
     * unpublished and ready on its own, and a reinforcement line goes out
     * with or after its source — never guessed, never completed.
     *
     * @param list<string> $stableIds
     *
     * @return list<PlanningLine>
     */
    private function selectNewLines(PublicationPreflight $preflight, array $stableIds): array
    {
        $stableIds = array_values(array_unique($stableIds));
        if ([] === $stableIds) {
            throw PublicationLineSelectionException::empty();
        }

        $byId = [];
        foreach ($preflight->lines as $readiness) {
            $byId[(string) $readiness->line->getStableId()] = $readiness;
        }
        $unknown = array_values(array_filter($stableIds, static fn (string $id): bool => !isset($byId[$id])));
        if ([] !== $unknown) {
            throw PublicationLineSelectionException::unknown($unknown);
        }

        $selected = array_map(static fn (string $id): PublicationLineReadiness => $byId[$id], $stableIds);
        $published = array_values(array_filter($selected, static fn (PublicationLineReadiness $line): bool => $line->isPublished()));
        if ([] !== $published) {
            throw PublicationLineSelectionException::alreadyPublished(array_map(static fn (PublicationLineReadiness $line): PlanningLine => $line->line, $published));
        }
        $notReady = array_values(array_filter($selected, static fn (PublicationLineReadiness $line): bool => !$line->isFirstPublishable()));
        if ([] !== $notReady) {
            throw PublicationLineSelectionException::notPublishable(array_map(static fn (PublicationLineReadiness $line): array => ['line' => $line->line, 'blockers' => $line->blockers], $notReady));
        }

        $lines = array_map(static fn (PublicationLineReadiness $line): PlanningLine => $line->line, $selected);
        $orphans = [];
        foreach ($selected as $readiness) {
            $source = $readiness->sourceLine;
            if (null !== $source && !\in_array($source, $lines, true) && !($preflight->line($source)?->isPublished() ?? false)) {
                $orphans[] = ['line' => $readiness->line, 'source' => $source];
            }
        }
        if ([] !== $orphans) {
            throw PublicationLineSelectionException::sourceNotPublished($orphans);
        }

        usort($lines, static fn (PlanningLine $a, PlanningLine $b): int => $a->getPosition() <=> $b->getPosition());

        return $lines;
    }

    /**
     * @return list<PublicationChange>
     */
    private function changesOnPublishedLines(Planning $planning, PlanningCycle $cycle, PlanningPublication $reference, PublicationPreflight $preflight): array
    {
        $published = array_map(static fn (PublicationLineReadiness $line): PlanningLine => $line->line, $preflight->publishedLines());

        return $this->changeService->changesSince($reference, $this->calendarReader->read($planning, static fn (PlanningLine $line): bool => \in_array($line, $published, true), $cycle));
    }

    /**
     * The preflight run again + status transitions + the publication record
     * + the notifications it owes, in one transaction, under the
     * CalendarWriteLock (no reassignment, no generation can slip between
     * checking the calendar, freezing it and deciding who is told what).
     *
     * @param callable(PublicationPreflight): array{0: ?PlanningPublication, 1: list<PlanningLine>, 2: bool} $decide
     *                                                                                                               the period's reference publication, the lines to publish for the first time, and
     *                                                                                                               whether the published lines' changes are announced — or an exception (nothing written)
     */
    private function record(Planning $planning, PlanningCycle $cycle, User $publishedBy, callable $decide): PublicationOutcome
    {
        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();
        try {
            $this->calendarWriteLock->acquire($planning);

            $preflight = $this->preflightService->check($planning, $cycle);
            [$reference, $firstLines, $announceChanges] = $decide($preflight);
            $publishedBefore = array_map(static fn (PublicationLineReadiness $line): PlanningLine => $line->line, $preflight->publishedLines());

            $results = [];
            foreach ($preflight->lines as $readiness) {
                $isFirst = \in_array($readiness->line, $firstLines, true);
                if ($isFirst) {
                    $period = $readiness->line->periodIn($cycle) ?? throw new \LogicException('A line of the preflight has a period in the cycle.');
                    if (PlanningPeriodStatus::GENERATED === $period->getStatus()) {
                        $this->lifecycleService->transition($period, PlanningPeriodStatus::VALIDATED);
                    }
                    $this->lifecycleService->transition($period, PlanningPeriodStatus::PUBLISHED);
                }
            }

            $published = [...$publishedBefore, ...$firstLines];
            $cells = $this->calendarReader->read($planning, static fn (PlanningLine $line): bool => \in_array($line, $published, true), $cycle);
            $changes = null !== $reference && $announceChanges
                ? array_values(array_filter($this->changeService->changesSince($reference, $cells), static fn (PublicationChange $change): bool => \in_array($change->line, $publishedBefore, true)))
                : [];
            if ($announceChanges && [] === $changes && [] === $firstLines) {
                // Reverted between the client's look and the lock: nothing to announce — rolls the transaction back.
                throw new NoUnpublishedChangesException();
            }

            $publication = new PlanningPublication(
                $planning,
                null === $reference ? PlanningPublicationKind::FIRST : PlanningPublicationKind::UPDATE,
                $publishedBy,
                \count($changes),
                new \DateTimeImmutable(),
                $this->isCycleComplete($preflight, $published),
            );
            // Before the first write: planning_publications is append-only.
            $publication->assignToCycle($cycle);
            $this->entityManager->persist($publication);

            $entriesByLine = [];
            foreach ($cells as $cell) {
                // docs/decisions.md D166: a conditional duty nobody needs and nobody holds is not part of what is
                // published — never recorded, so the publication PDF can never show it as "Non attribué".
                if (!$cell->isShown()) {
                    continue;
                }
                $this->entityManager->persist(new PlanningPublicationEntry($publication, $cell->duty, $cell->member));
                $entriesByLine[(int) $cell->line->getId()] = ($entriesByLine[(int) $cell->line->getId()] ?? 0) + 1;
            }

            $changedByLine = [];
            foreach ($changes as $change) {
                $changedByLine[(int) $change->line->getId()] = ($changedByLine[(int) $change->line->getId()] ?? 0) + 1;
            }
            foreach ($preflight->lines as $readiness) {
                $line = $readiness->line;
                $kind = match (true) {
                    \in_array($line, $firstLines, true) => PlanningPublicationLineKind::FIRST_FOR_LINE,
                    isset($changedByLine[(int) $line->getId()]) => PlanningPublicationLineKind::UPDATED,
                    default => null, // never an artificial UPDATED
                };
                if (null !== $kind) {
                    $period = $line->periodIn($cycle) ?? throw new \LogicException('A line of the preflight has a period in the cycle.');
                    $this->entityManager->persist(new PlanningPublicationLine($publication, $period, $line, $kind, $entriesByLine[(int) $line->getId()] ?? 0, $changedByLine[(int) $line->getId()] ?? 0));
                }
                $results[] = new PublicationLineResult($line, $line->periodIn($cycle)?->getStatus() ?? $readiness->periodStatus, $readiness->isPublished(), $kind);
            }

            $now = $this->clock->now();
            foreach ($this->recipients($cycle, $firstLines, $changes) as ['user' => $user, 'changes' => $personal, 'firstLines' => $lineNames]) {
                $this->entityManager->persist(new PlanningPublicationNotification($publication, $user, $personal, $now, $lineNames));
            }
            $this->entityManager->flush();

            // docs/decisions.md D173: the PDF of this version, rendered now from the entries just written and
            // stored — every email of this publication, retries included, attaches these bytes, whatever changes
            // later (an extension, a rename). Inside the transaction: a publication never exists without its PDF.
            $this->entityManager->persist($this->pdfRenderer->document($publication, $now));
            $this->entityManager->flush();

            $connection->commit();
        } catch (\Throwable $exception) {
            $connection->rollBack();
            throw $exception;
        }

        $delivery = $this->notificationSender->sendForPublication($publication);

        return new PublicationOutcome($publication, $results, $delivery['recipientCount'], $delivery['sentCount']);
    }

    /**
     * One recipient each (docs/decisions.md D172, D196): the members of the
     * lines published for the first time (whether or not they hold a duty),
     * and the people whose own duties changed on the published lines — a
     * person in both gets one email, with both.
     *
     * @param list<PlanningLine>      $firstLines
     * @param list<PublicationChange> $changes
     *
     * @return list<array{user: User, changes: list<array{kind: string, line: string, dutyType: ?string, block: ?string, dates: list<string>}>|null, firstLines: list<string>|null}>
     */
    private function recipients(PlanningCycle $cycle, array $firstLines, array $changes): array
    {
        $byUser = [];
        foreach ([] !== $firstLines ? $this->changeService->audienceForFirstLines($cycle, $firstLines) : [] as ['user' => $user, 'lines' => $lines]) {
            $byUser[(int) $user->getId()] = ['user' => $user, 'changes' => null, 'firstLines' => $lines];
        }
        foreach ($this->changeService->personalChanges($changes) as ['user' => $user, 'changes' => $personal]) {
            $byUser[(int) $user->getId()] = ['user' => $user, 'changes' => $personal, 'firstLines' => $byUser[(int) $user->getId()]['firstLines'] ?? null];
        }

        return array_values($byUser);
    }

    /**
     * docs/decisions.md D196: after this publication, every line that counts in the period is published and none of
     * them holds a required duty left uncovered or a reinforcement whose demand is unknown.
     *
     * @param list<PlanningLine> $published
     */
    private function isCycleComplete(PublicationPreflight $preflight, array $published): bool
    {
        foreach ($preflight->lines as $readiness) {
            if (!\in_array($readiness->line, $published, true)) {
                return false;
            }
        }

        return [] === $preflight->uncoveredDuties && [] === $preflight->undeterminedDuties;
    }

    /**
     * @return callable(PlanningLine): bool the lines published in $cycle
     */
    private function publishedIn(PlanningCycle $cycle): callable
    {
        return static fn (PlanningLine $line): bool => PlanningPeriodStatus::PUBLISHED === $line->periodIn($cycle)?->getStatus();
    }

    /**
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    private function locked(Planning $planning, callable $work): mixed
    {
        if (!$this->tryLock($planning)) {
            throw new PlanningPublicationInProgressException();
        }

        try {
            return $work();
        } finally {
            $this->unlock($planning);
        }
    }

    private function tryLock(Planning $planning): bool
    {
        return (bool) $this->entityManager->getConnection()->fetchOne(
            'SELECT pg_try_advisory_lock(:namespace, :planning)',
            ['namespace' => self::ADVISORY_LOCK_NAMESPACE, 'planning' => $planning->getId()],
        );
    }

    private function unlock(Planning $planning): void
    {
        $this->entityManager->getConnection()->fetchOne(
            'SELECT pg_advisory_unlock(:namespace, :planning)',
            ['namespace' => self::ADVISORY_LOCK_NAMESPACE, 'planning' => $planning->getId()],
        );
    }
}
