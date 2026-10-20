<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\PlanningCycle;
use App\Entity\PlanningLine;
use App\Repository\DutyPatternRepository;
use App\Repository\DutyRepository;
use App\Repository\PlanningGenerationRepository;
use App\Repository\PlanningLineDemandPolicyRepository;
use App\Repository\PlanningRuleSetRepository;
use App\Repository\PlanningTeamMemberRepository;

/**
 * The single place deciding whether a line takes part in a period
 * (docs/decisions.md D194, LineApplicability) — read by the cycle status,
 * the publication preflight and the calendar views, so they never disagree.
 *
 * A demand exists in the period as soon as the line has a duty there
 * (materialized from its weekly structure, a remainder block D192, or a
 * ponctual one) or an active recurring weekly structure (it is materialized
 * on demand, by the generation preflight). A conditional policy without any
 * structure and without duty demands nothing. Nothing here ever changes a
 * line: an empty line is reported, never deactivated.
 */
final class LineApplicabilityService
{
    public function __construct(
        private readonly DutyRepository $dutyRepository,
        private readonly DutyPatternRepository $patternRepository,
        private readonly PlanningGenerationRepository $generationRepository,
        private readonly PlanningRuleSetRepository $ruleSetRepository,
        private readonly PlanningTeamMemberRepository $teamMemberRepository,
        private readonly PlanningLineDemandPolicyRepository $policyRepository,
    ) {
    }

    public function forLine(PlanningLine $line, PlanningCycle $cycle): LineApplicability
    {
        if (!$line->isActive()) {
            return new LineApplicability(LineApplicability::INACTIVE, ['LINE_INACTIVE']);
        }
        $period = $line->periodIn($cycle);
        if (null === $period) {
            return new LineApplicability(LineApplicability::NOT_IN_PERIOD, ['NO_PERIOD_IN_CYCLE']);
        }

        $team = $line->getPlanningTeam();
        $dutyCount = \count($this->dutyRepository->findByPlanningPeriod($period));
        $patternCount = \count($this->patternRepository->findActiveRecurringByTeam($team));
        $policy = $this->policyRepository->findActiveForLine($line);
        $source = null !== $policy && $policy->getMode()->isConditional() ? $policy->getSourceLine() : null;
        $conditional = null !== $source;

        if (0 === $dutyCount && 0 === $patternCount) {
            return new LineApplicability(LineApplicability::NO_DEMAND, ['NO_DUTY', 'NO_WEEKLY_STRUCTURE'], 0, 0, $conditional);
        }

        $evidence = array_values(array_filter([
            $dutyCount > 0 ? 'DUTIES_MATERIALIZED' : null,
            $patternCount > 0 ? 'WEEKLY_STRUCTURE' : null,
            $conditional ? 'CONDITIONAL_POLICY' : null,
        ]));

        // Already generated: its prerequisites held — what it needs now is publication, not configuration.
        if (null !== $this->generationRepository->findMostRecentCompletedByPlanningPeriod($period)) {
            return new LineApplicability(LineApplicability::APPLICABLE, [...$evidence, 'GENERATED'], $dutyCount, $patternCount, $conditional);
        }

        $missing = $this->missingPrerequisites($line, $cycle);

        return [] === $missing
            ? new LineApplicability(LineApplicability::APPLICABLE, $evidence, $dutyCount, $patternCount, $conditional)
            : new LineApplicability(LineApplicability::INCOMPLETE, [...$evidence, ...$missing], $dutyCount, $patternCount, $conditional);
    }

    /**
     * The prerequisites of generating $line in $cycle that are missing right
     * now (`NO_ACTIVE_RULE_SET`, `NO_MEMBER_IN_PERIOD`,
     * `CONDITIONAL_SOURCE_NOT_IN_PERIOD`) — what makes a line with a demand
     * INCOMPLETE, and what blocks its generation (docs/decisions.md D195).
     * Read for any line the launch generates, including one generated before:
     * its prerequisites must still hold to generate it again.
     *
     * @return list<string>
     */
    public function missingPrerequisites(PlanningLine $line, PlanningCycle $cycle): array
    {
        $period = $line->periodIn($cycle);
        if (null === $period) {
            return [];
        }
        $team = $line->getPlanningTeam();
        $policy = $this->policyRepository->findActiveForLine($line);
        $source = null !== $policy && $policy->getMode()->isConditional() ? $policy->getSourceLine() : null;

        $missing = [];
        if (null === $this->ruleSetRepository->findActive($team)) {
            $missing[] = 'NO_ACTIVE_RULE_SET';
        }
        if ([] === $this->teamMemberRepository->findIntersecting($team, $period->getStartsAt(), $period->getEndsAt())) {
            $missing[] = 'NO_MEMBER_IN_PERIOD';
        }
        if (null !== $source && null === $source->periodIn($cycle)) {
            $missing[] = 'CONDITIONAL_SOURCE_NOT_IN_PERIOD';
        }

        return $missing;
    }

    /**
     * @param list<PlanningLine> $lines
     *
     * @return array<int, LineApplicability> by line id
     */
    public function forLines(array $lines, PlanningCycle $cycle): array
    {
        $result = [];
        foreach ($lines as $line) {
            $result[(int) $line->getId()] = $this->forLine($line, $cycle);
        }

        return $result;
    }
}
