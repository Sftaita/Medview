<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Planning;
use App\Entity\PlanningCycle;
use App\Entity\PlanningPeriodStatus;
use App\Repository\PlanningGenerationRepository;
use App\Repository\PlanningLineRepository;

/**
 * The single extension point for reading several planning periods at once
 * (docs/planning-cycles.md §9, docs/decisions.md D201): every line's period
 * in every planning period up to a given one, with the generation holding
 * its current calendar and whether that line period is published.
 *
 * Read only, recomputed at each call (never a stored counter, D132). Used by
 * the statistics (lot L5); a future lot may derive historical fairness
 * targets from it (docs/allocation-algorithm.md §4.4) — the solver does not
 * read it today.
 *
 * A line without a period in a planning period does not take part in it and
 * is simply absent; a period whose line was deleted (orphan, D191) has no
 * line to be read through and is absent too.
 */
final class CycleHistoryReader
{
    private const PUBLISHED_STATUSES = [PlanningPeriodStatus::PUBLISHED, PlanningPeriodStatus::ARCHIVED];

    public function __construct(
        private readonly PlanningLineRepository $lineRepository,
        private readonly PlanningGenerationRepository $generationRepository,
    ) {
    }

    /**
     * @return list<CycleLinePeriod> line by line (the planning's order), then period by period (by number), for the
     *                               planning periods numbered up to $through's, $through included
     */
    public function linePeriods(Planning $planning, PlanningCycle $through): array
    {
        $cycles = array_values(array_filter($planning->getCycles(), static fn (PlanningCycle $cycle): bool => $cycle->getNumber() <= $through->getNumber()));

        $linePeriods = [];
        foreach ($this->lineRepository->findByPlanning($planning) as $line) {
            foreach ($cycles as $cycle) {
                $period = $line->periodIn($cycle);
                if (null === $period) {
                    continue;
                }
                $linePeriods[] = new CycleLinePeriod(
                    $line,
                    $cycle,
                    $period,
                    $this->generationRepository->findMostRecentCompletedByPlanningPeriod($period),
                    \in_array($period->getStatus(), self::PUBLISHED_STATUSES, true),
                );
            }
        }

        return $linePeriods;
    }
}
