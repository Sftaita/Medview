<?php

declare(strict_types=1);

namespace App\Service;

use App\Demand\DemandCalculator;
use App\Demand\DemandRules;
use App\Demand\LiveDemandView;
use App\Demand\SourceHolding;
use App\Entity\Planning;
use App\Entity\PlanningLine;
use App\Entity\PlanningPeriod;
use App\Repository\DutyAssignmentRepository;
use App\Repository\DutyRepository;
use App\Repository\PlanningGenerationRepository;
use App\Repository\PlanningLineDemandPolicyRepository;
use App\Repository\PlanningLineRepository;
use App\Repository\PlanningSnapshotRepository;

/**
 * Loads the LIVE DemandView of a Planning (docs/decisions.md D163): each
 * line's demand rules, and — for every conditional line — who holds each
 * duty of its source line right now: the source line's current generation
 * (the most recent COMPLETED one, D125) and its current assignments
 * (DutyAssignment.current, D131). A reassignment on the source line is
 * therefore seen by the next view built, immediately; the duties
 * themselves are never touched (a CONDITIONAL duty stays CONDITIONAL).
 *
 * Rules (docs/decisions.md D164): a change of demand policy applies to the
 * NEXT generations, never retroactively to a calendar already generated.
 * So a conditional line that has a current generation (its most recent
 * COMPLETED one) is read with the policy version that generation froze;
 * only a line never generated yet is read with the policy in force. The
 * holders are always today's (DutyAssignment.current): a reassignment on
 * the source line changes the live demand at once, a new policy version
 * does not until the line is generated again.
 */
final class LiveDemandViewFactory
{
    public function __construct(
        private readonly PlanningLineRepository $lineRepository,
        private readonly PlanningLineDemandPolicyRepository $policyRepository,
        private readonly PlanningGenerationRepository $generationRepository,
        private readonly DutyAssignmentRepository $assignmentRepository,
        private readonly DutyRepository $dutyRepository,
        private readonly DemandCalculator $calculator,
        private readonly PlanningSnapshotRepository $snapshotRepository,
    ) {
    }

    public function forPlanning(Planning $planning): LiveDemandView
    {
        $rulesByPeriodId = [];
        $holdings = [];
        $loadedSourcePeriods = [];

        foreach ($this->lineRepository->findByPlanning($planning) as $line) {
            [$rules, $sourcePeriod] = $this->rulesOf($line);
            $rulesByPeriodId[(int) $line->getPlanningPeriod()->getId()] = $rules;

            if (null === $sourcePeriod || isset($loadedSourcePeriods[(int) $sourcePeriod->getId()])) {
                continue;
            }
            $loadedSourcePeriods[(int) $sourcePeriod->getId()] = true;

            $generation = $this->generationRepository->findMostRecentCompletedByPlanningPeriod($sourcePeriod);
            if (null === $generation) {
                continue; // never generated: every source duty reads as SOURCE_LINE_NOT_GENERATED
            }

            foreach ($this->dutyRepository->findByPlanningPeriod($sourcePeriod) as $sourceDuty) {
                $holdings[(int) $sourceDuty->getId()] = new SourceHolding(true, null);
            }
            foreach ($this->assignmentRepository->findForGenerations([$generation]) as $assignment) {
                $holdings[(int) $assignment->getDuty()->getId()] = new SourceHolding(true, (string) $assignment->getTeamMember()->getUser()->getStableId());
            }
        }

        return new LiveDemandView($this->calculator, $rulesByPeriodId, $holdings);
    }

    /**
     * The rules the live calendar reads $line with, and its source line's
     * period (null for an independent line).
     *
     * @return array{0: DemandRules, 1: ?PlanningPeriod}
     */
    private function rulesOf(PlanningLine $line): array
    {
        $generation = $this->generationRepository->findMostRecentCompletedByPlanningPeriod($line->getPlanningPeriod());
        $snapshot = null !== $generation ? $this->snapshotRepository->findOneByGeneration($generation) : null;
        if (null !== $snapshot) {
            $frozen = $snapshot->getDemandPolicy();
            if (null === $frozen) {
                // Generated as an independent line: its duties are intrinsic whatever policy exists since.
                return [DemandRules::independent(), null];
            }
            $sourceLine = $this->lineRepository->findOneByStableId((string) $frozen->getSourceLineStableId());

            return [$frozen->toRules(), $sourceLine?->getPlanningPeriod()];
        }

        $policy = $this->policyRepository->findActiveForLine($line);
        if (null === $policy || !$policy->getMode()->isConditional()) {
            return [DemandRules::independent(), null];
        }

        return [$policy->toRules(), $policy->getSourceLine()?->getPlanningPeriod()];
    }
}
