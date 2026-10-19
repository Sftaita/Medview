<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AvailabilityCollection;
use App\Entity\FairnessPeriod;
use App\Entity\Planning;
use App\Entity\PlanningCycle;
use App\Entity\PlanningLine;
use App\Entity\PlanningPeriod;
use App\Entity\PlanningPeriodStatus;
use App\Entity\User;
use App\Exception\HistoricalBlockCutConfirmationRequiredException;
use App\Exception\PeriodBoundarySplitsBlockException;
use App\Exception\PlanningCycleException;
use App\Repository\AvailabilityCollectionRepository;
use App\Repository\FairnessPeriodRepository;
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

    private const LOCKED_STATUSES = [PlanningPeriodStatus::VALIDATED, PlanningPeriodStatus::PUBLISHED, PlanningPeriodStatus::ARCHIVED];

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
        private readonly LoggerInterface $logger,
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
            $this->assertLongEnough($startsAt, $endsAt);
            $this->assertNoActiveJob($planning);
            // Lot L3, option A: neither its start (the boundary with the previous period) nor its end (the next
            // boundary) may cut a block of the lines that take part — the block would be lost in both periods. The one
            // exception (D192): a historical start, confirmed explicitly — never the end, always still to be chosen.
            $lines = array_values(array_filter($this->lineRepository->findByPlanning($planning), static fn (PlanningLine $line): bool => $line->isActive()));
            $this->boundaryGuard->assertNoCut($lines, [$endsAt]);
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
     * §5.2. Only the last period, while none of its line periods is
     * validated/published/archived. Growing keeps every collection and
     * generation as it is (the added days are simply not covered yet);
     * shrinking is refused as soon as the data would no longer fit.
     *
     * @return array{cycle: PlanningCycle, generationOutdated: bool}
     *
     * @throws PlanningCycleException
     */
    public function changeEnd(Planning $planning, PlanningCycle $cycle, \DateTimeImmutable $endsAt, \DateTimeImmutable $expectedEndsAt): array
    {
        if (!$this->creationEnabled) {
            throw PlanningCycleException::conflict('period_management_disabled', 'Managing the periods of a planning is not enabled.');
        }

        return $this->underPlanningLock($planning, function () use ($planning, $cycle, $endsAt, $expectedEndsAt): array {
            if ($this->lastOf($planning) !== $cycle) {
                throw PlanningCycleException::conflict('period_not_last', 'Only the end of the last period can change.');
            }
            if ($cycle->getEndsAt()->format('Y-m-d') !== $expectedEndsAt->format('Y-m-d')) {
                throw PlanningCycleException::conflict('period_changed', 'The end of this period changed since it was read: reload.');
            }
            $periods = $this->cycleRepository->periodsOf($cycle);
            $this->assertNoneLocked($periods);
            $this->assertNoActiveJob($planning);
            $this->assertLongEnough($cycle->getStartsAt(), $endsAt);
            // Lot L3, option A: the end of the last period is the next boundary — it never cuts a block.
            $this->boundaryGuard->assertNoCut(
                array_values(array_filter(array_map(static fn (PlanningPeriod $period): ?PlanningLine => $period->getLine(), $periods), static fn (?PlanningLine $line): bool => null !== $line && $line->isActive())),
                [$endsAt],
            );

            $current = $cycle->getEndsAt()->format('Y-m-d');
            $requested = $endsAt->format('Y-m-d');
            if ($requested === $current) {
                return ['cycle' => $cycle, 'generationOutdated' => false];
            }

            $grows = $requested > $current;
            if (!$grows) {
                if ($this->cycleRepository->countGenerations($cycle) > 0) {
                    throw PlanningCycleException::conflict('period_has_generation', 'This period was already generated: it can no longer be shortened.');
                }
                if ($this->cycleRepository->countDutiesFrom($cycle, $endsAt) > 0) {
                    throw PlanningCycleException::conflict('period_has_duties_after', 'Duties already exist after the new end.');
                }
                if ($this->cycleRepository->countCollectionsEndingAfter($cycle, $endsAt) > 0) {
                    throw PlanningCycleException::conflict('collection_beyond_end', 'An availability collection of this period goes beyond the new end.');
                }
            }

            $cycle->changeEnd($endsAt);
            foreach ($periods as $period) {
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

            // The current generation no longer covers the added days (coverage incomplete until regenerated or
            // completed — publishing still requires a complete coverage, D106). No snapshot is touched.
            $generationOutdated = $grows && null !== $this->cycleRepository->milestones($cycle)['generatedAt'];

            return ['cycle' => $cycle, 'generationOutdated' => $generationOutdated];
        });
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

    private function assertLongEnough(\DateTimeImmutable $startsAt, \DateTimeImmutable $endsAt): void
    {
        if ($endsAt <= $startsAt || (int) $startsAt->diff($endsAt)->days < self::MIN_DAYS) {
            throw PlanningCycleException::invalid('period_too_short', \sprintf('A period lasts at least %d days.', self::MIN_DAYS));
        }
    }

    private function assertNoActiveJob(Planning $planning): void
    {
        if (null !== $this->jobRepository->findActiveForPlanning($planning)) {
            throw PlanningCycleException::conflict('job_in_progress', 'A generation or a completion is in progress on this planning.');
        }
    }

    /**
     * @param list<PlanningPeriod> $periods
     */
    private function assertNoneLocked(array $periods): void
    {
        foreach ($periods as $period) {
            if (\in_array($period->getStatus(), self::LOCKED_STATUSES, true)) {
                throw PlanningCycleException::conflict('period_locked', 'This period is validated or published: its dates no longer change.');
            }
        }
    }
}
