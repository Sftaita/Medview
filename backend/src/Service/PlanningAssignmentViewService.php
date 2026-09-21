<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\DutyAssignment;
use App\Entity\Planning;
use App\Entity\PlanningGeneration;
use App\Entity\PlanningLine;
use App\Entity\User;
use App\Fairness\FairnessDimensionKey;
use App\Fairness\FairnessDimensionValues;
use App\Repository\DutyAssignmentRepository;
use App\Repository\PlanningGenerationRepository;
use App\Repository\PlanningLineRepository;

/**
 * Read model of "who is on duty when" for a Planning, whole team or one
 * person (docs/planning.md §14, D125). Reads what the engine produced — the
 * assignments of each line's most recent COMPLETED generation — and never
 * recomputes anything about fairness targets or exposure.
 *
 * The per-person summary counts that person's actual assignments with the
 * very same per-duty classification the fairness engine uses
 * (DimensionMembershipCalculator: total, weighted workload, Friday /
 * Saturday / Sunday, duty type). "Nights" is deliberately not a metric of
 * its own: no night flag exists in the model (FairnessDimensionType), so a
 * team that defines a NIGHT duty type sees it in the per-type breakdown.
 */
final class PlanningAssignmentViewService
{
    public function __construct(
        private readonly PlanningLineRepository $lineRepository,
        private readonly PlanningGenerationRepository $generationRepository,
        private readonly DutyAssignmentRepository $assignmentRepository,
        private readonly DimensionMembershipCalculator $membershipCalculator,
    ) {
    }

    /**
     * The generation each line currently shows: its most recent COMPLETED
     * one. Older generations stay in the database untouched (history is
     * never rewritten) but are not what the planning displays.
     *
     * @return array<int, array{line: PlanningLine, generation: PlanningGeneration}> keyed by line id
     */
    public function currentGenerations(Planning $planning): array
    {
        $result = [];
        foreach ($this->lineRepository->findByPlanning($planning) as $line) {
            $generation = $this->generationRepository->findMostRecentCompletedByPlanningPeriod($line->getPlanningPeriod());
            if (null !== $generation) {
                $result[(int) $line->getId()] = ['line' => $line, 'generation' => $generation];
            }
        }

        return $result;
    }

    /**
     * @param list<PlanningGeneration> $generations
     *
     * @return list<DutyAssignment>
     */
    public function assignments(array $generations, ?User $user, ?\DateTimeImmutable $from, ?\DateTimeImmutable $toExclusive): array
    {
        return $this->assignmentRepository->findForGenerations($generations, $user, $from, $toExclusive);
    }

    /**
     * @param list<DutyAssignment> $assignments one person's assignments (the whole displayed generation, not a month)
     *
     * @return array{totalDuties: int, weightedWorkload: float, fridays: int, saturdays: int, sundays: int, weekendDays: int, byDutyType: list<array{dutyTypeStableId: string, code: string, name: string, count: int}>}
     */
    public function summarize(array $assignments): array
    {
        $total = FairnessDimensionValues::empty();
        $byType = [];

        foreach ($assignments as $assignment) {
            $duty = $assignment->getDuty();
            $total = $total->plus($this->membershipCalculator->forDuty($duty));

            $type = $duty->getDutyType();
            $key = (string) $type->getStableId();
            $byType[$key] ??= ['dutyTypeStableId' => $key, 'code' => $type->getCode(), 'name' => $type->getName(), 'count' => 0];
            ++$byType[$key]['count'];
        }

        $saturdays = (int) $total->get(FairnessDimensionKey::saturday());
        $sundays = (int) $total->get(FairnessDimensionKey::sunday());

        return [
            'totalDuties' => (int) $total->get(FairnessDimensionKey::totalDuties()),
            'weightedWorkload' => $total->get(FairnessDimensionKey::weightedWorkload()),
            'fridays' => (int) $total->get(FairnessDimensionKey::friday()),
            'saturdays' => $saturdays,
            'sundays' => $sundays,
            'weekendDays' => $saturdays + $sundays,
            'byDutyType' => array_values($byType),
        ];
    }
}
