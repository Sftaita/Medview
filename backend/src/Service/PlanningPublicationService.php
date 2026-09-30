<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Planning;
use App\Entity\PlanningLine;
use App\Entity\PlanningPeriodStatus;
use App\Entity\PlanningPublication;
use App\Entity\PlanningPublicationDelivery;
use App\Entity\PlanningPublicationEntry;
use App\Entity\PlanningPublicationKind;
use App\Entity\PlanningPublicationNotification;
use App\Entity\PublicationNotificationStatus;
use App\Entity\User;
use App\Exception\NoUnpublishedChangesException;
use App\Exception\PlanningAlreadyPublishedException;
use App\Exception\PlanningNotPublishableException;
use App\Exception\PlanningNotYetPublishedException;
use App\Exception\PlanningPublicationInProgressException;
use App\Repository\PlanningLineRepository;
use App\Repository\PlanningPublicationDeliveryRepository;
use App\Repository\PlanningPublicationNotificationRepository;
use App\Repository\PlanningPublicationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * "Publier le planning" and "Republier les modifications"
 * (docs/decisions.md D133, D143) — a Planning-level facade over the
 * per-line `PlanningPeriodStatus` lifecycle, the same shape as generation
 * (D129): `PlanningPeriod` lives on the line, so publishing "the planning"
 * means transitioning every active line's period, always through
 * `PlanningPeriodLifecycleService::transition()` — including the
 * GENERATED → VALIDATED step, a purely technical transition never exposed
 * to the user.
 *
 * Every real diffusion is recorded (PlanningPublication + one
 * PlanningPublicationEntry per Duty: exactly what was announced), in the
 * same transaction as the status transitions, under the CalendarWriteLock
 * so the recorded calendar is a coherent state. That record is the
 * reference "Modifications non publiées" is computed against; a
 * republication becomes the new reference.
 *
 * The emails a diffusion owes are recorded in that same transaction
 * (PlanningPublicationNotification, docs/decisions.md D172: the recipients
 * and, for a republication, each one's own changes — computed from the
 * very cells being frozen), then sent after the commit by
 * PublicationNotificationSender — a mail failure never undoes a
 * publication, and is retried, never lost.
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
     * The first diffusion: every participant receives the PDF of what was published.
     *
     * @throws PlanningPublicationInProgressException
     * @throws PlanningAlreadyPublishedException
     * @throws PlanningNotPublishableException
     */
    public function publish(Planning $planning, User $publishedBy): PublicationOutcome
    {
        return $this->locked($planning, function () use ($planning, $publishedBy): PublicationOutcome {
            $activeLines = $this->activeLines($planning);
            if (null !== $this->publicationRepository->findLatestForPlanning($planning)
                || ([] !== $activeLines && $this->allAlreadyPublished($activeLines))) {
                throw new PlanningAlreadyPublishedException();
            }

            // Always re-run the real preflight here — never trust one the client loaded earlier.
            $preflight = $this->preflightService->check($planning);
            if (!$preflight->publishable) {
                throw new PlanningNotPublishableException($preflight);
            }

            [$publication, $results] = $this->record(
                $planning,
                $activeLines,
                $publishedBy,
                PlanningPublicationKind::FIRST,
                fn (): array => [0, array_map(
                    static fn (User $user): array => ['user' => $user, 'changes' => null],
                    $this->changeService->audienceForFirstPublication($planning),
                )],
            );

            $delivery = $this->notificationSender->sendForPublication($publication);

            return new PublicationOutcome($publication, $results, $delivery['recipientCount'], $delivery['sentCount']);
        });
    }

    /**
     * A later diffusion of the changes made since the last one
     * (docs/decisions.md D172): only the people whose own duties changed
     * are emailed — one email each, with their own changes only and the PDF
     * of the updated planning. Becomes the new reference.
     *
     * @throws PlanningPublicationInProgressException
     * @throws PlanningNotYetPublishedException
     * @throws NoUnpublishedChangesException
     * @throws PlanningNotPublishableException
     */
    public function republish(Planning $planning, User $publishedBy): PublicationOutcome
    {
        return $this->locked($planning, function () use ($planning, $publishedBy): PublicationOutcome {
            $reference = $this->publicationRepository->findLatestForPlanning($planning);
            if (null === $reference) {
                throw new PlanningNotYetPublishedException();
            }

            if ([] === $this->changeService->changesSince($reference, $this->calendarReader->read($planning))) {
                throw new NoUnpublishedChangesException();
            }

            $preflight = $this->preflightService->check($planning);
            if (!$preflight->republishable) {
                throw new PlanningNotPublishableException($preflight);
            }

            [$publication, $results] = $this->record(
                $planning,
                $this->activeLines($planning),
                $publishedBy,
                PlanningPublicationKind::UPDATE,
                // Computed again under the calendar lock, from the exact state being recorded: the entries, each
                // person's changes and the PDF (rendered from those entries) are one and the same version.
                function (array $lockedCells) use ($reference): array {
                    $changes = $this->changeService->changesSince($reference, $lockedCells);
                    if ([] === $changes) {
                        // Reverted between the check above and the lock: nothing to announce — rolls the transaction back.
                        throw new NoUnpublishedChangesException();
                    }

                    return [\count($changes), $this->changeService->personalChanges($changes)];
                },
            );

            $delivery = $this->notificationSender->sendForPublication($publication);

            return new PublicationOutcome($publication, $results, $delivery['recipientCount'], $delivery['sentCount']);
        });
    }

    /**
     * The state the calendar screen shows (docs/decisions.md D143).
     */
    public function state(Planning $planning): PublicationState
    {
        $latest = $this->publicationRepository->findLatestForPlanning($planning);
        if (null === $latest) {
            return new PublicationState(null, null, [], $this->publicationRepository->findByPlanning($planning));
        }

        $changes = $this->changeService->changesSince($latest, $this->calendarReader->read($planning));

        return new PublicationState($this->publicationRepository->findFirstForPlanning($planning), $latest, $changes, $this->publicationRepository->findByPlanning($planning));
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
     * Status transitions + the publication record + the notifications it
     * owes, in one transaction, under the CalendarWriteLock (no reassignment
     * can slip between reading the calendar, freezing it and deciding who is
     * told what).
     *
     * @param list<PlanningLine>                                                                                                                                                                     $activeLines
     * @param callable(list<CalendarCell>): array{0: int, 1: list<array{user: User, changes: list<array{kind: string, line: string, dutyType: ?string, block: ?string, dates: list<string>}>|null}>} $prepare
     *                                                                                                                                                                                                            the changed-duty count and each recipient (with their own changes for a republication), from the locked cells
     *
     * @return array{0: PlanningPublication, 1: list<PublicationLineResult>}
     */
    private function record(Planning $planning, array $activeLines, User $publishedBy, PlanningPublicationKind $kind, callable $prepare): array
    {
        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();
        try {
            $this->calendarWriteLock->acquire($planning);

            $results = [];
            foreach ($activeLines as $line) {
                $results[] = $this->publishLine($line);
            }

            $cells = $this->calendarReader->read($planning);
            [$changedDutyCount, $recipients] = $prepare($cells);
            $publication = new PlanningPublication($planning, $kind, $publishedBy, $changedDutyCount, new \DateTimeImmutable());
            $this->entityManager->persist($publication);
            foreach ($cells as $cell) {
                // docs/decisions.md D166: a conditional duty nobody needs and nobody holds is not part of what is
                // published — never recorded, so the publication PDF can never show it as "Non attribué".
                if (!$cell->isShown()) {
                    continue;
                }
                $this->entityManager->persist(new PlanningPublicationEntry($publication, $cell->duty, $cell->member));
            }
            $now = $this->clock->now();
            foreach ($recipients as ['user' => $user, 'changes' => $changes]) {
                $this->entityManager->persist(new PlanningPublicationNotification($publication, $user, $changes, $now));
            }
            $this->entityManager->flush();

            // docs/decisions.md D173: the PDF of this version, rendered now from the entries just written and
            // stored — every email of this publication, retries included, attaches these bytes, whatever changes
            // later (an extension, a rename). Inside the transaction: a publication never exists without its PDF.
            $this->entityManager->persist($this->pdfRenderer->document($publication, $now));
            $this->entityManager->flush();

            $connection->commit();

            return [$publication, $results];
        } catch (\Throwable $exception) {
            $connection->rollBack();
            throw $exception;
        }
    }

    /**
     * @return list<PlanningLine>
     */
    private function activeLines(Planning $planning): array
    {
        return array_values(array_filter($this->lineRepository->findByPlanning($planning), static fn (PlanningLine $line): bool => $line->isActive()));
    }

    /**
     * @param list<PlanningLine> $lines
     */
    private function allAlreadyPublished(array $lines): bool
    {
        foreach ($lines as $line) {
            if (PlanningPeriodStatus::PUBLISHED !== $line->getPlanningPeriod()->getStatus()) {
                return false;
            }
        }

        return true;
    }

    private function publishLine(PlanningLine $line): PublicationLineResult
    {
        $period = $line->getPlanningPeriod();
        if (PlanningPeriodStatus::PUBLISHED === $period->getStatus()) {
            return new PublicationLineResult($line, $period->getStatus(), alreadyPublished: true);
        }

        if (PlanningPeriodStatus::GENERATED === $period->getStatus()) {
            $this->lifecycleService->transition($period, PlanningPeriodStatus::VALIDATED);
        }
        $this->lifecycleService->transition($period, PlanningPeriodStatus::PUBLISHED);

        return new PublicationLineResult($line, $period->getStatus(), alreadyPublished: false);
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
