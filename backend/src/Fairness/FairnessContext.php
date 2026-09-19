<?php

declare(strict_types=1);

namespace App\Fairness;

use App\Eligibility\EligibilityMatrix;
use App\Entity\PlanningGeneration;
use App\Entity\PlanningLine;
use App\Entity\PlanningPeriod;
use App\Entity\PlanningSnapshot;
use App\Entity\PlanningTeam;

/**
 * The immutable fairness picture for exactly one PlanningLine/PlanningTeam/
 * PlanningPeriod/PlanningGeneration/PlanningSnapshot (docs/fairness.md
 * §Scope) — fairness is strictly independent per (PlanningLine,
 * PlanningTeam) pair (docs/planning.md §7), so nothing in this type can
 * ever mix data from two different lines, teams, or Plannings. Built once
 * by FairnessContextBuilder, never mutated afterwards.
 *
 * Every per-dimension quantity is a FairnessDimensionValues, never a raw
 * array — see that class for why. Per-candidate quantities are keyed
 * internally by `sourceUserStableId` (docs/decisions.md D082 Audit A) but
 * only ever exposed through accessor methods, never raw array indexing,
 * same convention as EligibilityMatrix.
 */
final readonly class FairnessContext
{
    /**
     * @param list<FairnessCandidate>                $candidates
     * @param list<FairnessDimensionKey>             $supportedDimensions
     * @param list<FairnessDimensionKey>             $applicableDimensions      subset of $supportedDimensions where total exposure > 0
     * @param array<string, FairnessDimensionValues> $dimensionMembershipByUnit keyed by DutyUnit::getStableKey()
     * @param array<string, FairnessDimensionValues> $effectiveExposure         keyed by sourceUserStableId
     * @param array<string, FairnessDimensionValues> $grossTargets              keyed by sourceUserStableId
     * @param array<string, FairnessDimensionValues> $discretionaryTargets      keyed by sourceUserStableId
     * @param list<StructurallyForcedUnit>           $structurallyForcedUnits
     * @param array<string, FairnessDimensionValues> $structurallyForcedLoad    keyed by sourceUserStableId
     */
    public function __construct(
        private PlanningLine $planningLine,
        private PlanningTeam $planningTeam,
        private PlanningPeriod $planningPeriod,
        private PlanningGeneration $planningGeneration,
        private PlanningSnapshot $planningSnapshot,
        private EligibilityMatrix $eligibilityMatrix,
        private array $candidates,
        private array $supportedDimensions,
        private array $applicableDimensions,
        private FairnessDimensionValues $requiredDemand,
        private array $dimensionMembershipByUnit,
        private array $effectiveExposure,
        private array $grossTargets,
        private array $discretionaryTargets,
        private array $structurallyForcedUnits,
        private array $structurallyForcedLoad,
    ) {
    }

    public function getPlanningLine(): PlanningLine
    {
        return $this->planningLine;
    }

    public function getPlanningTeam(): PlanningTeam
    {
        return $this->planningTeam;
    }

    public function getPlanningPeriod(): PlanningPeriod
    {
        return $this->planningPeriod;
    }

    public function getPlanningGeneration(): PlanningGeneration
    {
        return $this->planningGeneration;
    }

    public function getPlanningSnapshot(): PlanningSnapshot
    {
        return $this->planningSnapshot;
    }

    public function getEligibilityMatrix(): EligibilityMatrix
    {
        return $this->eligibilityMatrix;
    }

    /**
     * @return list<FairnessCandidate>
     */
    public function getCandidates(): array
    {
        return $this->candidates;
    }

    public function getCandidate(string $sourceUserStableId): ?FairnessCandidate
    {
        foreach ($this->candidates as $candidate) {
            if ($candidate->sourceUserStableId === $sourceUserStableId) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @return list<FairnessDimensionKey>
     */
    public function getSupportedDimensions(): array
    {
        return $this->supportedDimensions;
    }

    /**
     * @return list<FairnessDimensionKey>
     */
    public function getApplicableDimensions(): array
    {
        return $this->applicableDimensions;
    }

    /**
     * False means Σ effectiveExposure(all candidates, dimension) = 0 for
     * this dimension in this context — NOT_APPLICABLE
     * (docs/allocation-algorithm.md §5 "dimension sans aucun candidat
     * exposé → ignorée entièrement, pas de division par zéro"). Every
     * candidate's target on a non-applicable dimension is exactly 0, never
     * a divide-by-zero.
     */
    public function isApplicable(FairnessDimensionKey $dimension): bool
    {
        foreach ($this->applicableDimensions as $applicable) {
            if ($applicable->equals($dimension)) {
                return true;
            }
        }

        return false;
    }

    public function getRequiredDemand(): FairnessDimensionValues
    {
        return $this->requiredDemand;
    }

    public function getDimensionMembership(string $dutyUnitStableKey): FairnessDimensionValues
    {
        return $this->dimensionMembershipByUnit[$dutyUnitStableKey] ?? FairnessDimensionValues::empty();
    }

    public function getEffectiveExposure(string $sourceUserStableId): FairnessDimensionValues
    {
        return $this->effectiveExposure[$sourceUserStableId] ?? FairnessDimensionValues::empty();
    }

    public function getGrossTarget(string $sourceUserStableId): FairnessDimensionValues
    {
        return $this->grossTargets[$sourceUserStableId] ?? FairnessDimensionValues::empty();
    }

    public function getDiscretionaryTarget(string $sourceUserStableId): FairnessDimensionValues
    {
        return $this->discretionaryTargets[$sourceUserStableId] ?? FairnessDimensionValues::empty();
    }

    /**
     * @return list<StructurallyForcedUnit>
     */
    public function getStructurallyForcedUnits(): array
    {
        return $this->structurallyForcedUnits;
    }

    public function getStructurallyForcedLoad(string $sourceUserStableId): FairnessDimensionValues
    {
        return $this->structurallyForcedLoad[$sourceUserStableId] ?? FairnessDimensionValues::empty();
    }
}
