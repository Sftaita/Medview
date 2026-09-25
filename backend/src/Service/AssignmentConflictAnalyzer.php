<?php

declare(strict_types=1);

namespace App\Service;

use App\Eligibility\DutyUnit;
use App\Eligibility\EligibilityMatrix;
use App\Eligibility\ExclusionReason;
use App\Entity\PlanningSnapshot;
use App\Entity\RestPolicyOptions;
use App\Fairness\AssignmentConflict;

/**
 * The first real global constraint computation (docs/decisions.md D100,
 * docs/planning-solver.md §Contraintes globales) — links two otherwise
 * independently-eligible `(DutyUnit, candidate)` pairs to each other,
 * something no per-edge `EligibilityResult` can express.
 *
 * Two DutyUnits are candidate-*independent* incompatible: whether A and B
 * physically overlap, or fall inside a rest window, depends only on their
 * own Duties' timestamps — never on who might be assigned. So this
 * computes the incompatible *unit pairs* once (`O(units^2)`, deliberately
 * not `O(units^2 * candidates)` — docs/planning-solver.md §Performance),
 * then attributes each incompatible pair to every candidate eligible to
 * *both* units.
 *
 * `LEGAL_MIN_REST`/`TEAM_MIN_REST` are read from
 * `$snapshot->getGeneration()->getRestPolicy()` (docs/decisions.md D105)
 * — a per-*generation* choice, never a team-wide default silently applied
 * to every planning. `PlanningRuleSetConfiguration::$teamMinRestHours`
 * (Lot 6D) is no longer read here at all.
 */
final class AssignmentConflictAnalyzer
{
    public function __construct(
        private readonly RestGapCalculator $restGapCalculator,
    ) {
    }

    /**
     * @return list<AssignmentConflict>
     */
    public function analyze(EligibilityMatrix $matrix, PlanningSnapshot $snapshot): array
    {
        $restPolicy = $snapshot->getGeneration()->getRestPolicy();

        $units = $matrix->getDutyUnits();
        usort($units, static fn (DutyUnit $a, DutyUnit $b): int => $a->getStableKey() <=> $b->getStableKey());

        $conflicts = [];
        $unitCount = \count($units);

        for ($i = 0; $i < $unitCount; ++$i) {
            for ($j = $i + 1; $j < $unitCount; ++$j) {
                $left = $units[$i];
                $right = $units[$j];

                $reason = $this->classifyPair($left, $right, $restPolicy);
                if (null === $reason) {
                    continue;
                }

                foreach ($this->commonEligibleCandidates($matrix, $left, $right) as $candidateId) {
                    $conflicts[] = new AssignmentConflict($candidateId, $left->getStableKey(), $right->getStableKey(), $reason);
                }
            }
        }

        return $conflicts;
    }

    /**
     * `null` = the two units are fully compatible for any candidate.
     * Precedence when more than one reason would apply to the same pair
     * (docs/decisions.md D105 §Priorité, matching D101's original
     * ordering): `CONFLICT` (overlap) first, then `LEGAL_MIN_REST`, then
     * `TEAM_MIN_REST` — never more than one reason reported for the same
     * pair. This ordering is also the only mathematically consistent one:
     * `RestPolicyOptions` enforces `teamMinRestHours >= legalMinRestHours`
     * whenever both are enabled, so a gap violating LEGAL always also
     * violates TEAM — reporting LEGAL (the more fundamental rule) and
     * suppressing the redundant TEAM finding is a deliberate choice, not
     * an oversight.
     */
    private function classifyPair(DutyUnit $left, DutyUnit $right, RestPolicyOptions $restPolicy): ?ExclusionReason
    {
        $minGapHours = null;

        foreach ($left->getDuties() as $leftDuty) {
            foreach ($right->getDuties() as $rightDuty) {
                if ($leftDuty->overlapsWith($rightDuty)) {
                    return ExclusionReason::CONFLICT;
                }

                $gap = $this->restGapCalculator->gapHours($leftDuty, $rightDuty);
                if (null === $minGapHours || $gap < $minGapHours) {
                    $minGapHours = $gap;
                }
            }
        }

        if (null === $minGapHours) {
            return null;
        }

        if ($restPolicy->legalMinRestEnabled && $minGapHours < $restPolicy->legalMinRestHours) {
            return ExclusionReason::LEGAL_MIN_REST;
        }

        if ($restPolicy->teamMinRestEnabled && $minGapHours < $restPolicy->teamMinRestHours) {
            return ExclusionReason::TEAM_MIN_REST;
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function commonEligibleCandidates(EligibilityMatrix $matrix, DutyUnit $left, DutyUnit $right): array
    {
        $leftEligible = $this->eligibleCandidateIds($matrix, $left);
        $rightEligible = $this->eligibleCandidateIds($matrix, $right);

        $common = array_values(array_intersect($leftEligible, $rightEligible));
        sort($common);

        return $common;
    }

    /**
     * @return list<string>
     */
    private function eligibleCandidateIds(EligibilityMatrix $matrix, DutyUnit $unit): array
    {
        $ids = [];
        foreach ($matrix->getForDutyUnit($unit) as $candidateId => $result) {
            if ($result->eligible) {
                $ids[] = $candidateId;
            }
        }

        return $ids;
    }
}
