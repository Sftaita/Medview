<?php

declare(strict_types=1);

namespace App\Service;

use App\Demand\DemandCalculator;
use App\Demand\DemandRules;
use App\Demand\LiveDemandView;
use App\Demand\SourceHolding;
use App\Entity\Planning;
use App\Repository\DutyAssignmentRepository;
use App\Repository\DutyRepository;
use App\Repository\PlanningGenerationRepository;
use App\Repository\PlanningLineDemandPolicyRepository;
use App\Repository\PlanningLineRepository;

/**
 * Loads the LIVE DemandView of a Planning (docs/decisions.md D163): each
 * line's demand rules, and — for every conditional line — who holds each
 * duty of its source line right now: the source line's current generation
 * (the most recent COMPLETED one, D125) and its current assignments
 * (DutyAssignment.current, D131). A reassignment on the source line is
 * therefore seen by the next view built, immediately; the duties
 * themselves are never touched (a CONDITIONAL duty stays CONDITIONAL).
 *
 * Rules: the line's demand policy in force. (Whether the live calendar
 * should later use the rules frozen by the line's current generation
 * instead is decided with the generation lot — the view takes the rules
 * as data, the choice stays here.)
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
    ) {
    }

    public function forPlanning(Planning $planning): LiveDemandView
    {
        $rulesByPeriodId = [];
        $holdings = [];
        $loadedSourcePeriods = [];

        foreach ($this->lineRepository->findByPlanning($planning) as $line) {
            $policy = $this->policyRepository->findActiveForLine($line);
            $rules = $policy?->toRules() ?? DemandRules::independent();
            $rulesByPeriodId[(int) $line->getPlanningPeriod()->getId()] = $rules;

            $sourcePeriod = $rules->mode->isConditional() ? $policy?->getSourceLine()?->getPlanningPeriod() : null;
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
}
