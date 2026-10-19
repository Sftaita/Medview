<?php

declare(strict_types=1);

namespace App\Service;

use App\Demand\LiveCoverageState;
use App\Entity\Planning;
use App\Entity\PlanningCycle;
use App\Entity\PlanningLine;
use App\Entity\PlanningPeriodStatus;
use App\Repository\DutyAssignmentRepository;
use App\Repository\DutyRepository;
use App\Repository\PlanningGenerationRepository;
use App\Repository\PlanningLineRepository;

/**
 * The current calendar of a Planning, duty by duty (docs/decisions.md
 * D143): for every active line with a COMPLETED generation (D125), every
 * Duty of its period and its current holder (D131) or null. The one read
 * the publication snapshot, the "Modifications non publiées" diff, the
 * republication audience and the weekly reminder all share — never a
 * second interpretation of "current".
 *
 * docs/decisions.md D166: each conditional duty's cell carries its live
 * coverage state (LiveDemandView: the rules frozen by its line's current
 * generation, today's source holders), computed here once — the outputs
 * never re-derive the conditional rule.
 */
final class CurrentCalendarReader
{
    public function __construct(
        private readonly PlanningLineRepository $lineRepository,
        private readonly PlanningGenerationRepository $generationRepository,
        private readonly DutyRepository $dutyRepository,
        private readonly DutyAssignmentRepository $assignmentRepository,
        private readonly LiveDemandViewFactory $demandViewFactory,
    ) {
    }

    /**
     * $cycle (docs/decisions.md D194, lot L4a): the period read — only the
     * periods of the lines in it, never another period's duties. Omitted: the
     * planning's only period (refused on a planning with several,
     * MultiplePeriodsNotSupportedException), as before periods existed.
     *
     * @param (callable(PlanningLine): bool)|null $lineFilter restricts which active lines are read
     *
     * @return list<CalendarCell> chronological, line by line
     */
    public function read(Planning $planning, ?callable $lineFilter = null, ?PlanningCycle $cycle = null): array
    {
        $cells = [];
        $demand = null;
        foreach ($this->lineRepository->findByPlanning($planning) as $line) {
            if (!$line->isActive() || (null !== $lineFilter && !$lineFilter($line))) {
                continue;
            }

            // A line without period yet (docs/planning-cycles.md §5.5) has nothing to show.
            $period = null !== $cycle ? $line->periodIn($cycle) : $line->getSingleCyclePeriod();
            $generation = null !== $period ? $this->generationRepository->findMostRecentCompletedByPlanningPeriod($period) : null;
            if (null === $period || null === $generation) {
                continue;
            }

            $memberByDutyId = [];
            foreach ($this->assignmentRepository->findForGenerations([$generation]) as $assignment) {
                $memberByDutyId[(int) $assignment->getDuty()->getId()] = $assignment->getTeamMember();
            }

            foreach ($this->dutyRepository->findByPlanningPeriod($period) as $duty) {
                $member = $memberByDutyId[(int) $duty->getId()] ?? null;
                $state = null;
                if ($duty->isConditional()) {
                    $demand ??= $this->demandViewFactory->forPlanning($planning);
                    $state = LiveCoverageState::of($demand->forDuty($duty), null !== $member);
                }
                $cells[] = new CalendarCell($line, $duty, $member, $state);
            }
        }

        return $cells;
    }

    /**
     * What members see of a Planning (docs/decisions.md D198, lot L4e): the
     * current calendar of every period, of every cycle, whose OWN status is
     * PUBLISHED — never "the" period of a line, never the planning as a
     * whole. Each cycle is read on its own (read() with $cycle), so a duty
     * appears once, in the period it belongs to; a draft period of a line
     * whose other periods are published stays unseen.
     *
     * $from/$to (calendar dates "Y-m-d", $to exclusive) skip the cycles that
     * do not overlap that range — an optimisation only: callers still filter
     * the duties by date.
     *
     * @param (callable(PlanningLine): bool)|null $lineFilter restricts which active lines are read
     *
     * @return list<CalendarCell> period by period, then as read()
     */
    public function readPublished(Planning $planning, ?callable $lineFilter = null, ?string $from = null, ?string $to = null): array
    {
        $cells = [];
        foreach ($planning->getCycles() as $cycle) {
            if ((null !== $to && $cycle->getStartsAt()->format('Y-m-d') >= $to) || (null !== $from && $cycle->getEndsAt()->format('Y-m-d') <= $from)) {
                continue;
            }
            $filter = static fn (PlanningLine $line): bool => PlanningPeriodStatus::PUBLISHED === $line->periodIn($cycle)?->getStatus()
                && (null === $lineFilter || $lineFilter($line));
            array_push($cells, ...$this->read($planning, $filter, $cycle));
        }

        return $cells;
    }
}
