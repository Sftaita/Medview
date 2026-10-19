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

        // Every period of every line, whatever its cycle (docs/planning-cycles.md §5.6, lot L3): rules are kept per
        // period and holdings per duty, so the periods never mix. A line without period has no duty to read.
        foreach ($this->lineRepository->findByPlanning($planning) as $line) {
            foreach ($line->getPeriods() as $period) {
                $this->readPeriod($line, $period, $rulesByPeriodId, $holdings, $loadedSourcePeriods);
            }
        }

        return new LiveDemandView($this->calculator, $rulesByPeriodId, $holdings);
    }

    /**
     * @param array<int, DemandRules>   $rulesByPeriodId
     * @param array<int, SourceHolding> $holdings
     * @param array<int, true>          $loadedSourcePeriods
     */
    private function readPeriod(PlanningLine $line, PlanningPeriod $period, array &$rulesByPeriodId, array &$holdings, array &$loadedSourcePeriods): void
    {
        [$rules, $sourcePeriod] = $this->rulesOf($line, $period);
        $rulesByPeriodId[(int) $period->getId()] = $rules;

        if (null === $sourcePeriod || isset($loadedSourcePeriods[(int) $sourcePeriod->getId()])) {
            return;
        }
        $loadedSourcePeriods[(int) $sourcePeriod->getId()] = true;

        $generation = $this->generationRepository->findMostRecentCompletedByPlanningPeriod($sourcePeriod);
        if (null === $generation) {
            return; // never generated: every source duty reads as SOURCE_LINE_NOT_GENERATED
        }

        foreach ($this->dutyRepository->findByPlanningPeriod($sourcePeriod) as $sourceDuty) {
            $holdings[(int) $sourceDuty->getId()] = new SourceHolding(true, null);
        }
        foreach ($this->assignmentRepository->findForGenerations([$generation]) as $assignment) {
            $holdings[(int) $assignment->getDuty()->getId()] = new SourceHolding(true, (string) $assignment->getTeamMember()->getUser()->getStableId());
        }
    }

    /**
     * The rules the live calendar reads $line with, and its source line's
     * period (null for an independent line).
     *
     * @return array{0: DemandRules, 1: ?PlanningPeriod}
     */
    private function rulesOf(PlanningLine $line, PlanningPeriod $period): array
    {
        $generation = $this->generationRepository->findMostRecentCompletedByPlanningPeriod($period);
        $snapshot = null !== $generation ? $this->snapshotRepository->findOneByGeneration($generation) : null;
        if (null !== $snapshot) {
            $frozen = $snapshot->getDemandPolicy();
            if (null === $frozen) {
                // Generated as an independent line: its duties are intrinsic whatever policy exists since.
                return [DemandRules::independent(), null];
            }
            $sourceLine = $this->lineRepository->findOneByStableId((string) $frozen->getSourceLineStableId());

            return [$frozen->toRules(), $this->sourcePeriodIn($sourceLine, $period)];
        }

        $policy = $this->policyRepository->findActiveForLine($line);
        if (null === $policy || !$policy->getMode()->isConditional()) {
            return [DemandRules::independent(), null];
        }

        return [$policy->toRules(), $this->sourcePeriodIn($policy->getSourceLine(), $period)];
    }

    /** docs/planning-cycles.md §5.6: the source line's period in the same cycle as $period. */
    private function sourcePeriodIn(?PlanningLine $sourceLine, PlanningPeriod $period): ?PlanningPeriod
    {
        $cycle = $period->getCycle();

        return null !== $sourceLine && null !== $cycle ? $sourceLine->periodIn($cycle) : null;
    }
}
