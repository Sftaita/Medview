<?php

declare(strict_types=1);

namespace App\Service;

use App\Fairness\FairnessDimensionKey;
use App\Fairness\FairnessDimensionValues;

/**
 * grossTarget(user, d) = requiredDemand(d) × effectiveExposure(user, d) /
 * Σ_v effectiveExposure(v, d) (docs/allocation-algorithm.md §5,
 * docs/fairness.md §Targets). Fractional, never rounded here (rounding is
 * strictly a display concern).
 *
 * `discretionaryTargetAtSolve(user, d) = max(0, grossTarget(user, d) −
 * structurallyForcedLoad(user, d))` — docs/decisions.md D085 clarifies why
 * this subtraction is required for `deviation = discretionaryLoadAtSolve −
 * discretionaryTarget` (docs/allocation-algorithm.md §6) to stay
 * internally consistent: a duty already structurally forced onto someone
 * must not also inflate the discretionary share the future solve still
 * owes them.
 */
final class FairnessTargetService
{
    /**
     * @param array<string, FairnessDimensionValues> $effectiveExposure   keyed by sourceUserStableId
     * @param list<FairnessDimensionKey>             $supportedDimensions
     *
     * @return array{0: array<string, FairnessDimensionValues>, 1: list<FairnessDimensionKey>} [grossTargets keyed by sourceUserStableId, applicableDimensions]
     */
    public function buildGrossTargets(FairnessDimensionValues $requiredDemand, array $effectiveExposure, array $supportedDimensions): array
    {
        $totalExposure = FairnessDimensionValues::empty();
        foreach ($effectiveExposure as $userExposure) {
            $totalExposure = $totalExposure->plus($userExposure);
        }

        $applicableDimensions = [];
        foreach ($supportedDimensions as $dimension) {
            // NOT_APPLICABLE (docs/allocation-algorithm.md §5): no division
            // by zero, dimension simply excluded — every candidate's target
            // on it stays 0 by construction (never added below).
            if ($totalExposure->get($dimension) > 0.0) {
                $applicableDimensions[] = $dimension;
            }
        }

        $grossTargets = [];
        foreach ($effectiveExposure as $userStableId => $userExposure) {
            $values = FairnessDimensionValues::empty();
            foreach ($applicableDimensions as $dimension) {
                $userShare = $userExposure->get($dimension);
                if ($userShare <= 0.0) {
                    // effectiveExposure(user,d) = 0 -> grossTarget = 0, no entry needed.
                    continue;
                }

                $target = $requiredDemand->get($dimension) * $userShare / $totalExposure->get($dimension);
                $values = $values->withAdded($dimension, $target);
            }
            $grossTargets[$userStableId] = $values;
        }

        return [$grossTargets, $applicableDimensions];
    }

    /**
     * @param array<string, FairnessDimensionValues> $grossTargets           keyed by sourceUserStableId
     * @param array<string, FairnessDimensionValues> $structurallyForcedLoad keyed by sourceUserStableId
     * @param list<FairnessDimensionKey>             $dimensions
     *
     * @return array<string, FairnessDimensionValues> keyed by sourceUserStableId
     */
    public function buildDiscretionaryTargets(array $grossTargets, array $structurallyForcedLoad, array $dimensions): array
    {
        $result = [];

        foreach ($grossTargets as $userStableId => $userGrossTargets) {
            $forcedLoad = $structurallyForcedLoad[$userStableId] ?? FairnessDimensionValues::empty();

            $values = FairnessDimensionValues::empty();
            foreach ($dimensions as $dimension) {
                $discretionary = max(0.0, $userGrossTargets->get($dimension) - $forcedLoad->get($dimension));
                $values = $values->withAdded($dimension, $discretionary);
            }

            $result[$userStableId] = $values;
        }

        return $result;
    }
}
