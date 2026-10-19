<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Planning;
use App\Entity\PlanningCycle;
use App\Entity\PlanningLine;
use App\Entity\PlanningLineType;
use App\Entity\PlanningPeriodStatus;
use App\Entity\PlanningTeam;
use App\Exception\PlanningCycleException;
use App\Exception\PlanningLineIsDemandSourceException;
use App\Exception\PrimaryPlanningLineNotDeletableException;
use App\Repository\PlanningCycleRepository;
use App\Repository\PlanningLineDemandPolicyRepository;
use App\Repository\PlanningLineRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Owns PlanningLine creation/deletion (docs/planning.md §2-§3). A line's
 * PlanningTeam is never a pre-existing entity a client references — this
 * service always creates a brand new PlanningTeam together with the line,
 * atomically (docs/decisions.md D079). A PlanningTeam is thus never shared
 * between two PlanningLines or two Plannings. Never touches
 * EligibilityService/PlanningSnapshotService: once a PlanningPeriod
 * exists, every mono-team engine underneath it runs exactly as it did
 * before Planning existed (docs/planning.md §4).
 *
 * Periods (docs/planning-cycles.md §5.5, docs/decisions.md D187): a line
 * takes effect at the start of a period, never in the middle of one, and
 * never in a period that is over or already validated/published — no
 * history is ever made up. It gets a DRAFT period in its starting period and
 * in every later one already created; with none open to it, it is created
 * without period and takes part from the next period created.
 *
 * Single-period compatibility (docs/decisions.md D188): while the creation
 * of periods is disabled (MEDVUE_PERIOD_CREATION_ENABLED=0) and the Planning
 * has its only period, adding and deleting a line behave exactly as before
 * periods existed — the new line always gets its period, whatever the
 * period's status or dates, and a deleted line leaves its period and its
 * whole history behind, no longer attached to any line.
 */
final class PlanningLineService
{
    private const LOCKED_STATUSES = [PlanningPeriodStatus::VALIDATED, PlanningPeriodStatus::PUBLISHED, PlanningPeriodStatus::ARCHIVED];

    public function __construct(
        private readonly PlanningLineRepository $planningLineRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly PlanningLineDemandPolicyRepository $demandPolicyRepository,
        private readonly PlanningCycleRepository $cycleRepository,
        private readonly PlanningCycleService $cycleService,
        private readonly PlanningPeriodRemover $periodRemover,
        private readonly PlanningClock $clock,
    ) {
    }

    /**
     * $name is used both as the new PlanningTeam's name and as the
     * PlanningLine/FairnessPeriod/PlanningPeriod's name — there is no
     * separate "team name" a client can set in v1 (docs/decisions.md D079).
     *
     * $fromCycle: the period the line starts in. Default: the earliest
     * period from which every period is still open to it (§5.5). In
     * single-period compatibility (D188): always the only period.
     *
     * @throws PlanningCycleException 409 period_not_eligible / later_period_published
     */
    public function addLine(Planning $planning, string $name, PlanningLineType $type, ?PlanningCycle $fromCycle = null): PlanningLine
    {
        $cycles = $this->cycleRepository->findByPlanning($planning);
        if ($this->isSinglePeriodCompatible($cycles)) {
            return $this->createLine($planning, $name, $type, $cycles);
        }
        $today = $this->clock->today($planning);

        return $this->createLine($planning, $name, $type, $this->startingCycles($cycles, $today, $fromCycle));
    }

    /**
     * The primary line of a Planning being created: a period in its cycle 1,
     * whatever its dates — exactly as before periods existed.
     */
    public function addPrimaryLine(Planning $planning, string $name): PlanningLine
    {
        return $this->createLine($planning, $name, PlanningLineType::PRIMARY, $this->cycleRepository->findByPlanning($planning));
    }

    /**
     * @param list<PlanningCycle> $starting
     */
    private function createLine(Planning $planning, string $name, PlanningLineType $type, array $starting): PlanningLine
    {
        return $this->entityManager->wrapInTransaction(function () use ($planning, $name, $type, $starting): PlanningLine {
            $team = new PlanningTeam($planning, $name);
            $this->entityManager->persist($team);
            $position = $this->planningLineRepository->countByPlanning($planning) + 1;
            $line = new PlanningLine($planning, $team, null, $name, $type, $position);
            $this->entityManager->persist($line);
            // Flushed immediately: FairnessPeriodService::create() (in createPeriod()) queries for overlaps using
            // $team as a bound parameter, which Doctrine can only do once it has an assigned identifier.
            $this->entityManager->flush();

            foreach ($starting as $cycle) {
                $this->cycleService->createPeriod($line, $cycle);
            }

            return $line;
        });
    }

    /**
     * §5.5: allowed only for a line without history — no generation on any
     * of its periods (hence no assignment, event or publication entry), no
     * swap request, none of its duties covering another line's. Its periods
     * and their materialized duties go with it; its PlanningTeam and
     * FairnessPeriod stay (no deletion path exists for them,
     * docs/planning-domain.md §14).
     *
     * In single-period compatibility (D188), as before periods existed: the
     * line goes, its period stays with all its history (generations,
     * assignments, publication entries), attached to no line any more. Only
     * a line a swap request names is refused (409 line_has_history), where
     * the database used to refuse it.
     *
     * @throws PrimaryPlanningLineNotDeletableException
     * @throws PlanningLineIsDemandSourceException
     * @throws PlanningCycleException                   409 line_has_history
     */
    public function deleteLine(PlanningLine $line): void
    {
        if ($line->isPrimary()) {
            throw new PrimaryPlanningLineNotDeletableException();
        }

        // docs/decisions.md D162: a conditional line in force must never be left depending on nothing. The line's
        // own demand policies go with it (ON DELETE CASCADE); a RETIRED version of another line that named it as
        // source keeps its stable id as a value (source_line_id is set to null).
        if ([] !== $this->demandPolicyRepository->findActiveUsingSource($line)) {
            throw new PlanningLineIsDemandSourceException();
        }

        if ($this->isSinglePeriodCompatible($this->cycleRepository->findByPlanning($line->getPlanning()))) {
            if ($this->hasSwapRequest($line)) {
                throw PlanningCycleException::conflict('line_has_history', 'Swaps were requested on this line: it can no longer be deleted.');
            }
            $this->entityManager->wrapInTransaction(function () use ($line): void {
                foreach ($line->getPeriods() as $period) {
                    $period->detachFromLine();
                }
                $this->entityManager->remove($line);
                $this->entityManager->flush();
            });

            return;
        }

        if ($this->hasHistory($line)) {
            throw PlanningCycleException::conflict('line_has_history', 'This line was already generated or used: it can no longer be deleted.');
        }

        $this->entityManager->wrapInTransaction(function () use ($line): void {
            $this->periodRemover->remove($line->getPeriods());
            $this->entityManager->remove($line);
            $this->entityManager->flush();
        });
    }

    public function rename(PlanningLine $line, string $name): void
    {
        $line->rename($name);
        $this->entityManager->flush();
    }

    /**
     * D188: the creation of periods is disabled and the Planning has its only
     * period — the line rules of a planning without periods apply.
     *
     * @param list<PlanningCycle> $cycles
     */
    private function isSinglePeriodCompatible(array $cycles): bool
    {
        return !$this->cycleService->isCreationEnabled() && 1 === \count($cycles);
    }

    private function hasSwapRequest(PlanningLine $line): bool
    {
        return (bool) $this->entityManager->getConnection()->fetchOne(
            'SELECT EXISTS (SELECT 1 FROM duty_swap_requests WHERE line_id = :line)',
            ['line' => $line->getId()],
        );
    }

    /**
     * @param list<PlanningCycle> $cycles ordered by number
     *
     * @return list<PlanningCycle> the periods the new line gets a period in
     */
    private function startingCycles(array $cycles, \DateTimeImmutable $today, ?PlanningCycle $fromCycle): array
    {
        $eligible = array_map(fn (PlanningCycle $cycle): bool => $this->isOpenToNewLine($cycle, $today), $cycles);

        if (null !== $fromCycle) {
            $index = array_search($fromCycle, $cycles, true);
            if (false === $index || !$eligible[$index]) {
                throw PlanningCycleException::conflict('period_not_eligible', 'A line can only start in a period that is not over and not validated or published yet.');
            }
            foreach (\array_slice($eligible, $index + 1) as $laterEligible) {
                if (!$laterEligible) {
                    throw PlanningCycleException::conflict('later_period_published', 'A later period is already validated or published: start the line from a later period.');
                }
            }

            return \array_slice($cycles, $index);
        }

        // The earliest period from which every period is open to the line; none when the last one is not.
        $index = \count($cycles);
        while ($index > 0 && $eligible[$index - 1]) {
            --$index;
        }

        return \array_slice($cycles, $index);
    }

    /** Not over yet, and none of its periods validated/published/archived (§5.5). */
    private function isOpenToNewLine(PlanningCycle $cycle, \DateTimeImmutable $today): bool
    {
        if ($cycle->getEndsAt()->format('Y-m-d') <= $today->format('Y-m-d')) {
            return false;
        }
        foreach ($this->cycleRepository->periodsOf($cycle) as $period) {
            if (\in_array($period->getStatus(), self::LOCKED_STATUSES, true)) {
                return false;
            }
        }

        return true;
    }

    private function hasHistory(PlanningLine $line): bool
    {
        $connection = $this->entityManager->getConnection();

        return (bool) $connection->fetchOne(
            <<<'SQL'
                SELECT EXISTS (SELECT 1 FROM planning_generations g JOIN planning_periods p ON p.id = g.planning_period_id WHERE p.planning_line_id = :line)
                    OR EXISTS (SELECT 1 FROM duty_swap_requests WHERE line_id = :line)
                    OR EXISTS (SELECT 1 FROM duties d JOIN duties s ON s.id = d.coverage_source_id
                               JOIN planning_periods sp ON sp.id = s.planning_period_id
                               JOIN planning_periods dp ON dp.id = d.planning_period_id
                               WHERE sp.planning_line_id = :line AND dp.planning_line_id IS DISTINCT FROM :line)
            SQL,
            ['line' => $line->getId()],
        );
    }
}
