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
use App\Entity\User;
use App\Exception\NoUnpublishedChangesException;
use App\Exception\PlanningAlreadyPublishedException;
use App\Exception\PlanningNotPublishableException;
use App\Exception\PlanningNotYetPublishedException;
use App\Exception\PlanningPublicationInProgressException;
use App\Repository\PlanningLineRepository;
use App\Repository\PlanningPublicationRepository;
use Doctrine\ORM\EntityManagerInterface;

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
 * Emails are sent after the commit — a mail failure never undoes a
 * publication — and each attempt is recorded (PlanningPublicationDelivery).
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
        private readonly PlanningPublicationMailer $mailer,
        private readonly CalendarWriteLock $calendarWriteLock,
        private readonly EntityManagerInterface $entityManager,
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

            [$publication, $results] = $this->record($planning, $activeLines, $publishedBy, PlanningPublicationKind::FIRST, static fn (): int => 0);

            $pdf = $this->pdfRenderer->render($publication);
            $filename = $this->pdfRenderer->filename($publication);
            $periodLabel = $this->periodLabel($planning);
            $recipients = $this->changeService->audienceForFirstPublication($planning);
            $sent = $this->deliver($publication, $recipients, fn (User $user): bool => $this->mailer->sendFirstPublication($user, $planning, $periodLabel, $pdf, $filename));

            return new PublicationOutcome($publication, $results, \count($recipients), $sent);
        });
    }

    /**
     * A later diffusion of the changes made since the last one: only the
     * people concerned by an impacted date are emailed, with the details of
     * what changed (no PDF). Becomes the new reference.
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

            $changes = [];
            $cells = [];
            [$publication, $results] = $this->record(
                $planning,
                $this->activeLines($planning),
                $publishedBy,
                PlanningPublicationKind::UPDATE,
                // Computed again under the calendar lock, from the exact state being recorded.
                function (array $lockedCells) use ($reference, &$changes, &$cells): int {
                    $cells = $lockedCells;
                    $changes = $this->changeService->changesSince($reference, $lockedCells);
                    if ([] === $changes) {
                        // Reverted between the check above and the lock: nothing to announce — rolls the transaction back.
                        throw new NoUnpublishedChangesException();
                    }

                    return \count($changes);
                },
            );

            $digest = $this->changeService->digest($changes, $cells);
            $recipients = $this->changeService->audienceForChanges($changes, $cells);
            $sent = $this->deliver($publication, $recipients, fn (User $user): bool => $this->mailer->sendRepublication($user, $planning, $digest));

            return new PublicationOutcome($publication, $results, \count($recipients), $sent);
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
     * Status transitions + the publication record, in one transaction, under
     * the CalendarWriteLock (no reassignment can slip between reading the
     * calendar and freezing it).
     *
     * @param list<PlanningLine>                $activeLines
     * @param callable(list<CalendarCell>): int $changedDutyCount
     *
     * @return array{0: PlanningPublication, 1: list<PublicationLineResult>}
     */
    private function record(Planning $planning, array $activeLines, User $publishedBy, PlanningPublicationKind $kind, callable $changedDutyCount): array
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
            $publication = new PlanningPublication($planning, $kind, $publishedBy, $changedDutyCount($cells), new \DateTimeImmutable());
            $this->entityManager->persist($publication);
            foreach ($cells as $cell) {
                $this->entityManager->persist(new PlanningPublicationEntry($publication, $cell->duty, $cell->member));
            }
            $this->entityManager->flush();

            $connection->commit();

            return [$publication, $results];
        } catch (\Throwable $exception) {
            $connection->rollBack();
            throw $exception;
        }
    }

    /**
     * @param list<User>           $recipients
     * @param callable(User): bool $send
     */
    private function deliver(PlanningPublication $publication, array $recipients, callable $send): int
    {
        $sent = 0;
        foreach ($recipients as $user) {
            $ok = $send($user);
            $sent += $ok ? 1 : 0;
            $this->entityManager->persist(new PlanningPublicationDelivery($publication, $user, $ok, new \DateTimeImmutable()));
        }
        $this->entityManager->flush();

        return $sent;
    }

    /**
     * "du 1er octobre 2026 au 31 décembre 2026" — the planning's own dates (end exclusive).
     */
    private function periodLabel(Planning $planning): string
    {
        return 'du '.FrenchDate::long($planning->getStartsAt()).' au '.FrenchDate::long($planning->getEndsAt()->modify('-1 day'));
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
