<?php

declare(strict_types=1);

namespace App\Service;

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
 */
final class CurrentCalendarReader
{
    public function __construct(
        private readonly PlanningLineRepository $lineRepository,
        private readonly PlanningGenerationRepository $generationRepository,
        private readonly DutyRepository $dutyRepository,
        private readonly DutyAssignmentRepository $assignmentRepository,
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
                $cells[] = new CalendarCell($line, $duty, $memberByDutyId[(int) $duty->getId()] ?? null);
            }
        }

        return $cells;
    }
}
