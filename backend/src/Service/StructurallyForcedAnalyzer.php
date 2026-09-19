<?php

declare(strict_types=1);

namespace App\Service;

use App\Eligibility\ConstraintTier;
use App\Eligibility\EligibilityMatrix;
use App\Eligibility\EligibilityResult;
use App\Fairness\FairnessDimensionValues;
use App\Fairness\StructurallyForcedUnit;

/**
 * STRUCTURALLY_FORCED(dutyUnit, U) = U is the unique HARD-eligible
 * candidate for dutyUnit (docs/allocation-algorithm.md §4.2,
 * docs/fairness.md §StructurallyForced). Local, pre-solve, computed
 * exhaustively from the already-built EligibilityMatrix alone — no
 * fairness data, no solver, ever.
 *
 * "HARD-eligible" is deliberately **not** `EligibilityResult::$eligible` —
 * that flag is false for *any* exclusion regardless of tier. A candidate
 * excluded only by POLICY_HARD reasons (TEAM_MIN_REST, MAX_DUTIES,
 * MAX_WEEKENDS, MAX_CONSECUTIVE_NIGHTS, RULE_EXCLUSION — none actually
 * produced by EligibilityService yet, docs/eligibility.md §3) must still
 * count as HARD-eligible here: a future POLICY_HARD exclusion must never
 * manufacture a STRUCTURALLY_FORCED that would not have existed under HARD
 * constraints alone.
 *
 * Operates at the EligibilityMatrix's own granularity — one
 * PlanningSnapshotMember *stint*, not one User (docs/decisions.md D082
 * Audit A) — because that is what a real DutyAssignment would actually
 * reference. The winning stint's `sourceUserStableId` is what
 * StructurallyForcedUnit carries forward, so FairnessContextBuilder can
 * aggregate forced load at the User granularity everything else in this
 * lot uses.
 */
final class StructurallyForcedAnalyzer
{
    public function __construct(private readonly DimensionMembershipCalculator $dimensionMembershipCalculator)
    {
    }

    /**
     * @return list<StructurallyForcedUnit>
     */
    public function analyze(EligibilityMatrix $matrix): array
    {
        $forced = [];

        foreach ($matrix->getDutyUnits() as $dutyUnit) {
            $hardEligibleMembers = [];

            foreach ($matrix->getCandidates() as $member) {
                $result = $matrix->get($dutyUnit, $member);
                if (null !== $result && $this->isHardEligible($result)) {
                    $hardEligibleMembers[] = $member;
                }
            }

            if (1 === \count($hardEligibleMembers)) {
                $forced[] = new StructurallyForcedUnit($dutyUnit, (string) $hardEligibleMembers[0]->getSourceUserStableId());
            }
        }

        return $forced;
    }

    /**
     * structurallyForcedLoad(user, dimension) — docs/fairness.md
     * §StructurallyForcedLoad. A forced DutyUnit contributes through
     * **all** of its analytical dimensions, not a flat `+1`: a forced
     * Friday+Saturday+Sunday group contributes TOTAL_DUTIES += 3,
     * FRIDAY += 1, SATURDAY += 1, SUNDAY += 1 to that User, exactly like
     * DimensionMembershipCalculator::forDutyUnit() already does for any
     * other purpose — no separate counting rule invented here.
     *
     * @param list<StructurallyForcedUnit> $forcedUnits
     *
     * @return array<string, FairnessDimensionValues> keyed by sourceUserStableId
     */
    public function buildForcedLoad(array $forcedUnits): array
    {
        $load = [];

        foreach ($forcedUnits as $forcedUnit) {
            $contribution = $this->dimensionMembershipCalculator->forDutyUnit($forcedUnit->dutyUnit);
            $existing = $load[$forcedUnit->sourceUserStableId] ?? FairnessDimensionValues::empty();
            $load[$forcedUnit->sourceUserStableId] = $existing->plus($contribution);
        }

        return $load;
    }

    private function isHardEligible(EligibilityResult $result): bool
    {
        foreach ($result->exclusions as $exclusion) {
            if (ConstraintTier::HARD === $exclusion->tier) {
                return false;
            }
        }

        return true;
    }
}
