<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AvailabilityCollection;
use App\Entity\FairnessPeriod;
use App\Entity\Planning;
use App\Entity\PlanningCycle;
use App\Entity\PlanningCycleRectification;
use App\Entity\PlanningLine;
use App\Entity\PlanningPeriod;
use App\Entity\User;
use App\Exception\HistoricalBlockCutConfirmationRequiredException;
use App\Exception\PeriodBoundarySplitsBlockException;
use App\Exception\PlanningCycleException;
use App\Repository\AvailabilityCollectionRepository;
use App\Repository\FairnessPeriodRepository;
use App\Repository\PeriodPreparationRepository;
use App\Repository\PlanningCycleRepository;
use App\Repository\PlanningJobRepository;
use App\Repository\PlanningLineRepository;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The periods of a Planning (docs/planning-cycles.md §5.1-§5.3, §5.9,
 * docs/decisions.md D187): create the next one, change the end of the last
 * one, delete the last one, open a collection on one.
 *
 * Every operation runs in one transaction holding a PESSIMISTIC_WRITE lock
 * on the Planning row (the same lock as PlanningExtensionService): two
 * concurrent requests are serialized, and the second one re-reads what the
 * first one wrote. The client names what it saw (the last period, the
 * current end): a request based on a stale view is refused (409) instead of
 * acting on a period the user did not see. The database is the last net
 * (no-overlap exclusions, unique numbers, one period per line and cycle):
 * any violation rolls everything back.
 *
 * Nothing here ever touches another period than the one named (I7): no
 * duty, generation, publication or collection of an earlier period is read
 * for writing. A new period starts empty — no collection, no email, no duty,
 * no assignment copied (A).
 *
 * Disabled unless MEDVUE_PERIOD_CREATION_ENABLED is set (§6.1): while it is
 * off, no Planning ever gets a second period.
 */
final class PlanningCycleService
{
    public const MIN_DAYS = 7;
    /** The reason of a rectification of a locked period's end (D211), in characters. */
    public const RECTIFICATION_REASON_MAX = 500;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PlanningCycleRepository $cycleRepository,
        private readonly PlanningLineRepository $lineRepository,
        private readonly FairnessPeriodRepository $fairnessPeriodRepository,
        private readonly FairnessPeriodService $fairnessPeriodService,
        private readonly PlanningPeriodLifecycleService $periodLifecycle,
        private readonly PlanningPeriodRemover $periodRemover,
        private readonly PlanningJobRepository $jobRepository,
        private readonly AvailabilityCollectionRepository $collectionRepository,
        private readonly AvailabilityCollectionService $collectionService,
        private readonly PeriodBoundaryGuard $boundaryGuard,
        private readonly PeriodDateRules $dateRules,
        private readonly LoggerInterface $logger,
        private readonly PeriodPreparationRepository $preparationRepository,
        #[Autowire('%env(bool:MEDVUE_PERIOD_CREATION_ENABLED)%')]
        private readonly bool $creationEnabled,
    ) {
    }

    public function isCreationEnabled(): bool
    {
        return $this->creationEnabled;
    }

    /**
     * §5.1. Starts the day the last period ends (never chosen by the client).
     *
     * @throws PlanningCycleException
     */
    /**
     * $acceptHistoricalBlockCuts (docs/decisions.md D192): the explicit
     * confirmation that the blocks a historical boundary cuts — the end of a
     * locked previous period — become remainder blocks of the new period.
     * Without it, such a cut refuses the creation (409, with the details); it
     * never covers an ordinary cut.
     *
     * @throws PeriodBoundarySplitsBlockException
     * @throws HistoricalBlockCutConfirmationRequiredException
     */
    public function createNext(Planning $planning, string $previousPeriodStableId, \DateTimeImmutable $endsAt, User $createdBy, bool $acceptHistoricalBlockCuts = false): PlanningCycle
    {
        if (!$this->creationEnabled) {
            throw PlanningCycleException::conflict('period_creation_disabled', 'Creating a following period is not enabled.');
        }

        return $this->underPlanningLock($planning, function () use ($planning, $previousPeriodStableId, $endsAt, $createdBy, $acceptHistoricalBlockCuts): PlanningCycle {
            $last = $this->lastOf($planning);
            if ((string) $last->getStableId() !== $previousPeriodStableId) {
                throw PlanningCycleException::conflict('period_sequence_changed', 'The last period is no longer the one the request was based on: reload.');
            }
            $startsAt = $last->getEndsAt();
            // Lot L3, option A: neither its start (the boundary with the previous period) nor its end (the next
            // boundary) may cut a block of the lines that take part — the block would be lost in both periods. The one
            // exception (D192): a historical start, confirmed explicitly — never the end, always still to be chosen.
            // The end follows the same rules as the date picker's options (D207).
            self::throwFirst($this->dateRules->nextEndViolations($last, $endsAt));
            $this->assertNoActiveJob($planning);
            $lines = array_values(array_filter($this->lineRepository->findByPlanning($planning), static fn (PlanningLine $line): bool => $line->isActive()));
            [$historical, $ordinary] = $this->boundaryGuard->classifyStartCuts($last, $lines);
            if ([] !== $ordinary) {
                throw new PeriodBoundarySplitsBlockException($ordinary);
            }
            if ([] !== $historical && !$acceptHistoricalBlockCuts) {
                throw new HistoricalBlockCutConfirmationRequiredException($historical);
            }

            $cycle = new PlanningCycle($planning, $last->getNumber() + 1, $startsAt, $endsAt, $createdBy);
            $this->entityManager->persist($cycle);
            $this->entityManager->flush();

            // One period per active line, the conditional ones included (their source gets its period in the same
            // transaction, docs/planning-cycles.md §5.6). An inactive line takes no part.
            foreach ($this->lineRepository->findByPlanning($planning) as $line) {
                if ($line->isActive()) {
                    $this->createPeriod($line, $cycle);
                }
            }
            $planning->changeEnvelope($planning->getStartsAt(), $endsAt);
            $this->entityManager->flush();

            $this->logger->info('Planning period {number} created on planning {planning}.', ['number' => $cycle->getNumber(), 'planning' => (string) $planning->getStableId()]);
            foreach ($historical as $cut) {
                // D192: the anomaly, as confirmed — which line, which block, which days stay without duty, which become a remainder.
                $this->logger->notice('Historical block cut confirmed when creating period {number} of planning {planning}: line {line}, block "{block}", boundary {boundary}, earlier days {earlier} (never materialized, left as they are), remainder {remainder}, confirmed by {user}.', [
                    'number' => $cycle->getNumber(),
                    'planning' => (string) $planning->getStableId(),
                    'line' => (string) $cut->line?->getStableId(),
                    'block' => $cut->blockName,
                    'boundary' => $cut->boundary->format('Y-m-d'),
                    'earlier' => implode(',', array_map(static fn (\DateTimeImmutable $d): string => $d->format('Y-m-d'), $cut->earlierDays())),
                    'remainder' => implode(',', array_map(static fn (\DateTimeImmutable $d): string => $d->format('Y-m-d'), $cut->remainderDays())),
                    'user' => (string) $createdBy->getStableId(),
                ]);
            }

            return $cycle;
        });
    }

    /**
     * §5.2. The end of a period (docs/decisions.md D207, PeriodDateRules):
     * the last one as before — it grows keeping every collection and
     * generation (the added days are simply not covered yet), shrinks while
     * nothing lies beyond; an earlier one only while it and the next one are
     * blank, the next one's start moving with it (no gap, no overlap).
     *
     * @return array{cycle: PlanningCycle, generationOutdated: bool}
     *
     * @throws PlanningCycleException
     * @throws PeriodBoundarySplitsBlockException
     */
    public function changeEnd(Planning $planning, PlanningCycle $cycle, \DateTimeImmutable $endsAt, \DateTimeImmutable $expectedEndsAt): array
    {
        return $this->changeDates($planning, $cycle, null, null, $endsAt, $expectedEndsAt);
    }

    /**
     * New dates for $cycle (docs/decisions.md D207): its end ($endsAt,
     * exclusive), and its start ($startsAt) for the first period only — a
     * following period starts where the previous one ends. Each date comes
     * with the one the request was based on: a stale view is refused (409
     * `period_changed`). Everything happens under the planning's lock, in
     * one transaction; the rules are PeriodDateRules', the same the date
     * picker shows.
     *
     * @return array{cycle: PlanningCycle, generationOutdated: bool}
     *
     * @throws PlanningCycleException
     * @throws PeriodBoundarySplitsBlockException
     */
    public function changeDates(Planning $planning, PlanningCycle $cycle, ?\DateTimeImmutable $startsAt, ?\DateTimeImmutable $expectedStartsAt, ?\DateTimeImmutable $endsAt, ?\DateTimeImmutable $expectedEndsAt): array
    {
        if (!$this->creationEnabled) {
            throw PlanningCycleException::conflict('period_management_disabled', 'Managing the periods of a planning is not enabled.');
        }
        if (null === $startsAt && null === $endsAt) {
            throw PlanningCycleException::invalid('invalid_request', 'Give the new start (startsAt), the new end (endsAt), or both.');
        }

        return $this->underPlanningLock($planning, function () use ($cycle, $startsAt, $expectedStartsAt, $endsAt, $expectedEndsAt): array {
            if (null !== $endsAt && $cycle->getEndsAt()->format('Y-m-d') !== $expectedEndsAt?->format('Y-m-d')) {
                throw PlanningCycleException::conflict('period_changed', 'The end of this period changed since it was read: reload.');
            }
            if (null !== $startsAt && $cycle->getStartsAt()->format('Y-m-d') !== $expectedStartsAt?->format('Y-m-d')) {
                throw PlanningCycleException::conflict('period_changed', 'The start of this period changed since it was read: reload.');
            }

            // Moving the whole period earlier: its start first, so that no intermediate state is too short.
            $generationOutdated = false;
            if (null !== $startsAt && $startsAt < $cycle->getStartsAt()) {
                $this->moveStart($cycle, $startsAt);
                $startsAt = null;
            }
            if (null !== $endsAt) {
                $generationOutdated = $this->moveEnd($cycle, $endsAt);
            }
            if (null !== $startsAt) {
                $this->moveStart($cycle, $startsAt);
            }
            $this->assertStillContinuous($cycle->getPlanning());

            return ['cycle' => $cycle, 'generationOutdated' => $generationOutdated];
        });
    }

    /**
     * docs/decisions.md D211 — the exceptional rectification of a locked
     * period's end: the boundary with the next (blank) period is brought back
     * over trailing days of the locked period that hold no duty of any line,
     * so that a block the old boundary cut goes whole into the next period.
     * The calendar of the locked period — duties, assignments, publications,
     * snapshots — is not touched; only its dates, its lines' periods, the next
     * period's start and the registries that follow them. Explicitly
     * confirmed, with a reason, by the creator; recorded in the append-only
     * journal (PlanningCycleRectification). Same rules for the options shown
     * and for this write (PeriodDateRules::rectificationViolations()).
     *
     * @throws PlanningCycleException
     */
    public function rectifyLockedEnd(Planning $planning, PlanningCycle $cycle, \DateTimeImmutable $endsAt, \DateTimeImmutable $expectedEndsAt, User $by, string $reason, bool $confirmed): PlanningCycle
    {
        if (!$this->creationEnabled) {
            throw PlanningCycleException::conflict('period_management_disabled', 'Managing the periods of a planning is not enabled.');
        }
        if (!$confirmed) {
            throw PlanningCycleException::invalid('rectification_confirmation_required', 'This exceptional correction must be confirmed explicitly.');
        }
        $reason = trim($reason);
        if ('' === $reason || mb_strlen($reason) > self::RECTIFICATION_REASON_MAX) {
            throw PlanningCycleException::invalid('rectification_reason_required', \sprintf('Say why, in at most %d characters.', self::RECTIFICATION_REASON_MAX));
        }

        return $this->underPlanningLock($planning, function () use ($planning, $cycle, $endsAt, $expectedEndsAt, $by, $reason): PlanningCycle {
            if ($cycle->getEndsAt()->format('Y-m-d') !== $expectedEndsAt->format('Y-m-d')) {
                throw PlanningCycleException::conflict('period_changed', 'The end of this period changed since it was read: reload.');
            }
            self::throwFirst($this->dateRules->rectificationViolations($cycle, $endsAt));
            $next = $this->nextOf($cycle) ?? throw new \LogicException('Checked by the rules: a next period exists.');
            $previousEndsAt = $cycle->getEndsAt();
            $lastDutyDay = $this->cycleRepository->dateFacts($cycle)['lastDutyDay'];
            $nextFacts = $this->cycleRepository->dateFacts($next);

            // The registries first take both sides of the boundary, then fit their periods again.
            $fairnessPeriods = $this->fairnessOf([$cycle, $next]);
            foreach ($fairnessPeriods as $fairness) {
                $fairness->extendTo(min($fairness->getStartsAt(), $endsAt), max($fairness->getEndsAt(), $endsAt));
            }
            $this->entityManager->flush();
            // The locked period shrinks first, so that the two never overlap, not even within the transaction.
            $cycle->changeEnd($endsAt);
            foreach ($this->cycleRepository->periodsOf($cycle) as $period) {
                $period->rectifyEnd($endsAt);
            }
            $this->entityManager->flush();
            $this->shiftStart($next, $endsAt);
            $this->fitFairness($fairnessPeriods);
            $this->followUntouchedCollection($nextFacts);
            $this->entityManager->persist(new PlanningCycleRectification(
                $cycle,
                $next,
                $previousEndsAt,
                $endsAt,
                null !== $lastDutyDay ? new \DateTimeImmutable($lastDutyDay) : null,
                $reason,
                $by,
                new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            ));
            $this->assertStillContinuous($planning);
            $this->logger->warning('Locked period {number} of planning {planning}: end rectified from {from} to {to} (exclusive) by user {user}.', [
                'number' => $cycle->getNumber(),
                'planning' => (string) $planning->getStableId(),
                'from' => $previousEndsAt->format('Y-m-d'),
                'to' => $endsAt->format('Y-m-d'),
                'user' => (string) $by->getStableId(),
            ]);

            return $cycle;
        });
    }

    /**
     * @return bool whether the current generation no longer covers the period
     */
    private function moveEnd(PlanningCycle $cycle, \DateTimeImmutable $endsAt): bool
    {
        self::throwFirst($this->dateRules->endViolations($cycle, $endsAt));
        $current = $cycle->getEndsAt()->format('Y-m-d');
        $requested = $endsAt->format('Y-m-d');
        if ($requested === $current) {
            return false;
        }
        $grows = $requested > $current;
        $planning = $cycle->getPlanning();
        $next = $this->nextOf($cycle);
        $facts = $this->cycleRepository->dateFacts($cycle);

        if (null === $next) {
            // The last period: the end of the planning moves with it.
            $cycle->changeEnd($endsAt);
            foreach ($this->cycleRepository->periodsOf($cycle) as $period) {
                $fairness = $period->getFairnessPeriod();
                if ($grows && $fairness->getEndsAt() < $endsAt) {
                    $fairness->extendTo($fairness->getStartsAt(), $endsAt);
                }
                $period->changeEnd($endsAt);
                if (!$grows && $fairness->getEndsAt() > $endsAt) {
                    $fairness->changeEnd($endsAt);
                }
            }
            $planning->changeEnvelope($planning->getStartsAt(), $endsAt);
            $this->entityManager->flush();
            $this->followUntouchedCollection($facts);
            $this->entityManager->flush();

            // The current generation no longer covers the added days (coverage incomplete until regenerated or
            // completed — publishing still requires a complete coverage, D106). No snapshot is touched.
            return $grows && null !== $this->cycleRepository->milestones($cycle)['generatedAt'];
        }

        // The boundary with the next period (both blank, PeriodDateRules): the shrinking side moves first, so that
        // the two periods never overlap, not even within the transaction (exclusion constraints).
        $nextFacts = $this->cycleRepository->dateFacts($next);
        $fairnessPeriods = $this->fairnessOf([$cycle, $next]);
        foreach ($fairnessPeriods as $fairness) {
            $fairness->extendTo(min($fairness->getStartsAt(), $endsAt), max($fairness->getEndsAt(), $endsAt));
        }
        $this->entityManager->flush();
        if ($grows) {
            $this->shiftStart($next, $endsAt);
            $this->shiftEnd($cycle, $endsAt);
        } else {
            $this->shiftEnd($cycle, $endsAt);
            $this->shiftStart($next, $endsAt);
        }
        $this->fitFairness($fairnessPeriods);
        $this->followUntouchedCollection($facts);
        $this->followUntouchedCollection($nextFacts);
        $this->entityManager->flush();
        $this->logger->info('Boundary between periods {number} and {next} of planning {planning} moved to {boundary}.', [
            'number' => $cycle->getNumber(),
            'next' => $next->getNumber(),
            'planning' => (string) $planning->getStableId(),
            'boundary' => $requested,
        ]);

        return false;
    }

    /** The first period's own start (PeriodDateRules: blank; the planning's start moves with it). */
    private function moveStart(PlanningCycle $cycle, \DateTimeImmutable $startsAt): void
    {
        self::throwFirst($this->dateRules->startViolations($cycle, $startsAt));
        if ($startsAt->format('Y-m-d') === $cycle->getStartsAt()->format('Y-m-d')) {
            return;
        }
        $planning = $cycle->getPlanning();
        $facts = $this->cycleRepository->dateFacts($cycle);
        $fairnessPeriods = $this->fairnessOf([$cycle]);
        foreach ($fairnessPeriods as $fairness) {
            $fairness->extendTo(min($fairness->getStartsAt(), $startsAt), $fairness->getEndsAt());
        }
        $this->shiftStart($cycle, $startsAt);
        $this->fitFairness($fairnessPeriods);
        $planning->changeEnvelope($startsAt, $planning->getEndsAt());
        $this->entityManager->flush();
        $this->followUntouchedCollection($facts);
        $this->entityManager->flush();
    }

    /**
     * What changeDates() commits, checked before the commit (docs/decisions.md
     * D208): the periods of $planning follow each other without gap nor
     * overlap, each line period has the dates of its period and stays inside
     * its registry, every collection inside its period, the planning spans
     * them all — the blocking invariants of PlanningCycleAudit, for this
     * planning. Anything else is a bug: thrown inside the transaction, so
     * that nothing of the move is kept.
     */
    private function assertStillContinuous(Planning $planning): void
    {
        $this->entityManager->flush();
        $broken = $this->entityManager->getConnection()->fetchAssociative(<<<'SQL'
            SELECT
              (SELECT COUNT(*) FROM planning_cycles a JOIN planning_cycles b ON b.planning_id = a.planning_id AND b.number = a.number + 1
                WHERE a.planning_id = :planning AND b.starts_at <> a.ends_at) AS gaps,
              (SELECT COUNT(*) FROM planning_periods pp JOIN planning_cycles c ON c.id = pp.planning_cycle_id
                WHERE c.planning_id = :planning AND pp.planning_line_id IS NOT NULL AND (pp.starts_at <> c.starts_at OR pp.ends_at <> c.ends_at)) AS line_periods,
              (SELECT COUNT(*) FROM planning_periods pp JOIN planning_cycles c ON c.id = pp.planning_cycle_id JOIN fairness_periods f ON f.id = pp.fairness_period_id
                WHERE c.planning_id = :planning AND pp.planning_line_id IS NOT NULL AND (pp.starts_at < f.starts_at OR pp.ends_at > f.ends_at)) AS registries,
              (SELECT COUNT(*) FROM availability_collections a JOIN planning_cycles c ON c.id = a.planning_cycle_id
                WHERE c.planning_id = :planning AND (a.starts_at < c.starts_at OR a.ends_at > c.ends_at)) AS collections,
              (SELECT COUNT(*) FROM plannings p
                WHERE p.id = :planning AND (p.starts_at <> (SELECT MIN(starts_at) FROM planning_cycles WHERE planning_id = p.id)
                                         OR p.ends_at <> (SELECT MAX(ends_at) FROM planning_cycles WHERE planning_id = p.id))) AS envelope
            SQL, ['planning' => $planning->getId()]);
        $broken = array_filter(false === $broken ? [] : array_map('intval', $broken));
        if ([] !== $broken) {
            throw new \LogicException(\sprintf('The dates of the periods would no longer hold together (%s): nothing was changed.', implode(', ', array_keys($broken))));
        }
    }

    private function shiftStart(PlanningCycle $cycle, \DateTimeImmutable $startsAt): void
    {
        $cycle->changeStart($startsAt);
        foreach ($this->cycleRepository->periodsOf($cycle) as $period) {
            $period->changeStart($startsAt);
        }
        $this->entityManager->flush();
    }

    private function shiftEnd(PlanningCycle $cycle, \DateTimeImmutable $endsAt): void
    {
        $cycle->changeEnd($endsAt);
        foreach ($this->cycleRepository->periodsOf($cycle) as $period) {
            $period->changeEnd($endsAt);
        }
        $this->entityManager->flush();
    }

    /**
     * @param list<PlanningCycle> $cycles
     *
     * @return list<FairnessPeriod> the registries of the lines of $cycles, each once
     */
    private function fairnessOf(array $cycles): array
    {
        $fairnessPeriods = [];
        foreach ($cycles as $cycle) {
            foreach ($this->cycleRepository->periodsOf($cycle) as $period) {
                $fairnessPeriods[spl_object_id($period->getFairnessPeriod())] = $period->getFairnessPeriod();
            }
        }

        return array_values($fairnessPeriods);
    }

    /**
     * Each registry back to exactly the span of its periods (docs/planning-cycles.md §3.5): it follows them.
     *
     * @param list<FairnessPeriod> $fairnessPeriods
     */
    private function fitFairness(array $fairnessPeriods): void
    {
        $this->entityManager->flush();
        $connection = $this->entityManager->getConnection();
        foreach ($fairnessPeriods as $fairness) {
            $span = $connection->fetchAssociative('SELECT MIN(starts_at)::text AS first, MAX(ends_at)::text AS last FROM planning_periods WHERE fairness_period_id = ?', [$fairness->getId()]);
            if (false === $span || null === $span['first']) {
                continue;
            }
            if ($fairness->getEndsAt()->format('Y-m-d') !== $span['last']) {
                $fairness->changeEnd(new \DateTimeImmutable($span['last']));
            }
            if ($fairness->getStartsAt()->format('Y-m-d') !== $span['first']) {
                $fairness->changeStart(new \DateTimeImmutable($span['first']));
            }
        }
        $this->entityManager->flush();
    }

    /**
     * @param array{untouchedCollectionId: ?int} $facts read before the dates moved
     */
    private function followUntouchedCollection(array $facts): void
    {
        if (null === $facts['untouchedCollectionId']) {
            return;
        }
        $collection = $this->collectionRepository->find($facts['untouchedCollectionId']);
        if (null !== $collection) {
            $this->collectionService->followPeriod($collection);
        }
    }

    private function nextOf(PlanningCycle $cycle): ?PlanningCycle
    {
        foreach ($this->cycleRepository->findByPlanning($cycle->getPlanning()) as $candidate) {
            if ($candidate->getNumber() === $cycle->getNumber() + 1) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param list<PeriodDateViolation> $violations
     */
    private static function throwFirst(array $violations): void
    {
        if ([] !== $violations) {
            throw $violations[0]->toException();
        }
    }

    /**
     * §5.3. Only the last period, never period 1, and only while it has no
     * history at all — no collection (even unanswered), generation,
     * publication or job.
     *
     * @throws PlanningCycleException
     */
    public function deleteLast(Planning $planning, PlanningCycle $cycle, string $expectedLastStableId): void
    {
        if (!$this->creationEnabled) {
            throw PlanningCycleException::conflict('period_management_disabled', 'Managing the periods of a planning is not enabled.');
        }

        $this->underPlanningLock($planning, function () use ($planning, $cycle, $expectedLastStableId): void {
            $cycles = $this->cycleRepository->findByPlanning($planning);
            $last = $cycles[\count($cycles) - 1];
            if ((string) $last->getStableId() !== $expectedLastStableId) {
                throw PlanningCycleException::conflict('period_sequence_changed', 'The last period is no longer the one the request was based on: reload.');
            }
            if ($last !== $cycle || 1 === $cycle->getNumber()) {
                throw PlanningCycleException::conflict('period_not_deletable', 'Only the last period, from period 2 on, can be deleted.');
            }
            if ($this->cycleRepository->countCollections($cycle) > 0) {
                throw PlanningCycleException::conflict('period_has_collection', 'An availability collection was opened on this period: it can no longer be deleted.');
            }
            if ($this->cycleRepository->countGenerations($cycle) > 0) {
                throw PlanningCycleException::conflict('period_has_generation', 'This period was generated: it can no longer be deleted.');
            }
            if ($this->cycleRepository->countPublications($cycle) > 0) {
                throw PlanningCycleException::conflict('period_has_publication', 'This period was published: it can no longer be deleted.');
            }
            if ($this->cycleRepository->countJobs($cycle) > 0) {
                throw PlanningCycleException::conflict('period_has_job', 'A calculation was run on this period: it can no longer be deleted.');
            }

            $previous = $cycles[\count($cycles) - 2];
            $periods = $this->cycleRepository->periodsOf($cycle);
            /** @var array<int, FairnessPeriod> $fairnessPeriods */
            $fairnessPeriods = [];
            foreach ($periods as $period) {
                $fairnessPeriods[(int) $period->getFairnessPeriod()->getId()] = $period->getFairnessPeriod();
            }

            $this->periodRemover->remove($periods);
            // docs/decisions.md D210: what was planned for it, never sent (a period with a collection is never deleted).
            $this->preparationRepository->forgetCycle($cycle);
            $planning->unregisterCycle($cycle);
            $this->entityManager->remove($cycle);
            $this->entityManager->flush();

            // The team's registry follows its periods: back to the end of the previous one, or gone with its last one.
            foreach ($fairnessPeriods as $fairness) {
                $remaining = (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM planning_periods WHERE fairness_period_id = ?', [$fairness->getId()]);
                if (0 === $remaining) {
                    $this->entityManager->remove($fairness);
                } elseif ($fairness->getEndsAt() > $previous->getEndsAt()) {
                    $fairness->changeEnd($previous->getEndsAt());
                }
            }
            $planning->changeEnvelope($planning->getStartsAt(), $previous->getEndsAt());
            $this->entityManager->flush();

            $this->logger->info('Planning period {number} deleted from planning {planning}.', ['number' => $cycle->getNumber(), 'planning' => (string) $planning->getStableId()]);
        });
    }

    /**
     * §5.9. Manual, never on creation of the period (A). Without dates: the
     * first part of the period not covered by a collection yet. No email is
     * sent on opening (as today).
     *
     * @throws PlanningCycleException
     */
    public function openCollection(
        Planning $planning,
        PlanningCycle $cycle,
        User $openedBy,
        ?\DateTimeImmutable $deadline,
        ?\DateTimeImmutable $startsAt,
        ?\DateTimeImmutable $endsAt,
    ): AvailabilityCollection {
        if (!$this->creationEnabled) {
            throw PlanningCycleException::conflict('period_management_disabled', 'Managing the periods of a planning is not enabled.');
        }
        $this->collectionService->assertDeadlineNotPast($planning, $deadline);

        return $this->underPlanningLock($planning, function () use ($planning, $cycle, $openedBy, $deadline, $startsAt, $endsAt): AvailabilityCollection {
            if (null === $startsAt && null === $endsAt) {
                $window = $this->uncoveredWindows($cycle)[0] ?? throw PlanningCycleException::conflict('period_fully_collected', 'Every day of this period is already covered by a collection.');
            } else {
                $from = $startsAt ?? $cycle->getStartsAt();
                $to = $endsAt ?? $cycle->getEndsAt();
                if ($to <= $from || !$cycle->contains($from, $to)) {
                    throw PlanningCycleException::invalid('window_outside_period', 'The window must lie inside the period.');
                }
                $window = new DateWindow($from, $to);
            }

            return $this->collectionService->open($planning, $window, $openedBy, $deadline, $cycle);
        });
    }

    /**
     * The parts of $cycle no collection covers, in order.
     *
     * @return list<DateWindow>
     */
    public function uncoveredWindows(PlanningCycle $cycle): array
    {
        $collections = array_values(array_filter(
            $this->collectionRepository->findByPlanning($cycle->getPlanning()),
            static fn (AvailabilityCollection $collection): bool => $collection->getCycle() === $cycle,
        ));
        usort($collections, static fn (AvailabilityCollection $a, AvailabilityCollection $b): int => $a->getStartsAt() <=> $b->getStartsAt());

        $windows = [];
        $cursor = $cycle->getStartsAt();
        foreach ($collections as $collection) {
            if ($collection->getStartsAt()->format('Y-m-d') > $cursor->format('Y-m-d')) {
                $windows[] = new DateWindow($cursor, $collection->getStartsAt());
            }
            if ($collection->getEndsAt()->format('Y-m-d') > $cursor->format('Y-m-d')) {
                $cursor = $collection->getEndsAt();
            }
        }
        if ($cycle->getEndsAt()->format('Y-m-d') > $cursor->format('Y-m-d')) {
            $windows[] = new DateWindow($cursor, $cycle->getEndsAt());
        }

        return $windows;
    }

    /** The number of days of $cycle no collection covers. */
    public function uncoveredDays(PlanningCycle $cycle): int
    {
        $days = 0;
        foreach ($this->uncoveredWindows($cycle) as $window) {
            $days += (int) $window->startsAt->diff($window->endsAt)->days;
        }

        return $days;
    }

    /**
     * Gives $line its period in $cycle: DRAFT, the cycle's dates, inside the
     * team's FairnessPeriod (extended to the cycle's end, or created when the
     * team has none yet — a line that never had a period, §3.5). Shared with
     * PlanningLineService::addLine().
     */
    public function createPeriod(PlanningLine $line, PlanningCycle $cycle): PlanningPeriod
    {
        $team = $line->getPlanningTeam();
        $fairness = $this->fairnessPeriodRepository->findOneBy(['team' => $team], ['endsAt' => 'DESC']);
        if (null === $fairness) {
            $fairness = $this->fairnessPeriodService->create($team, $line->getName(), $cycle->getStartsAt(), $cycle->getEndsAt());
        } elseif ($fairness->getEndsAt() < $cycle->getEndsAt()) {
            $fairness->extendTo(min($fairness->getStartsAt(), $cycle->getStartsAt()), $cycle->getEndsAt());
        }

        $period = $this->periodLifecycle->create($team, $fairness, $line->getName(), $cycle->getStartsAt(), $cycle->getEndsAt());
        $period->attachToCycle($cycle);
        $period->bindToLine($line);
        $this->entityManager->flush();

        return $period;
    }

    /**
     * @template T
     *
     * @param callable(): T $operation
     *
     * @return T
     *
     * @throws PlanningCycleException
     */
    private function underPlanningLock(Planning $planning, callable $operation): mixed
    {
        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();
        try {
            // Serializes every period operation of this Planning; what a concurrent request committed is seen below.
            $this->entityManager->refresh($planning, LockMode::PESSIMISTIC_WRITE);
            $result = $operation();
            $connection->commit();

            return $result;
        } catch (UniqueConstraintViolationException $exception) {
            $connection->rollBack();
            throw PlanningCycleException::conflict('period_sequence_changed', 'The periods of this planning changed meanwhile: reload.');
        } catch (DriverException $exception) {
            $connection->rollBack();
            // 23P01: an exclusion constraint (overlapping periods) — the same race, caught by the database.
            if ('23P01' === $exception->getSQLState()) {
                throw PlanningCycleException::conflict('period_sequence_changed', 'The periods of this planning changed meanwhile: reload.');
            }
            throw $exception;
        } catch (\Throwable $exception) {
            $connection->rollBack();
            throw $exception;
        }
    }

    private function lastOf(Planning $planning): PlanningCycle
    {
        $cycles = $this->cycleRepository->findByPlanning($planning);

        return $cycles[\count($cycles) - 1] ?? throw new \LogicException('Every Planning has its cycle 1 (docs/planning-cycles.md I1).');
    }

    private function assertNoActiveJob(Planning $planning): void
    {
        if (null !== $this->jobRepository->findActiveForPlanning($planning)) {
            throw PlanningCycleException::conflict('job_in_progress', 'A generation or a completion is in progress on this planning.');
        }
    }
}
