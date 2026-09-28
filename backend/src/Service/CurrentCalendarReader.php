<?php

declare(strict_types=1);

namespace App\Service;

use App\Demand\LiveCoverageState;
use App\Entity\Planning;
use App\Entity\PlanningLine;
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
     * @param (callable(PlanningLine): bool)|null $lineFilter restricts which active lines are read
     *
     * @return list<CalendarCell> chronological, line by line
     */
    public function read(Planning $planning, ?callable $lineFilter = null): array
    {
        $cells = [];
        $demand = null;
        foreach ($this->lineRepository->findByPlanning($planning) as $line) {
            if (!$line->isActive() || (null !== $lineFilter && !$lineFilter($line))) {
                continue;
            }

            $generation = $this->generationRepository->findMostRecentCompletedByPlanningPeriod($line->getPlanningPeriod());
            if (null === $generation) {
                continue;
            }

            $memberByDutyId = [];
            foreach ($this->assignmentRepository->findForGenerations([$generation]) as $assignment) {
                $memberByDutyId[(int) $assignment->getDuty()->getId()] = $assignment->getTeamMember();
            }

            foreach ($this->dutyRepository->findByPlanningPeriod($line->getPlanningPeriod()) as $duty) {
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
}
