<?php

declare(strict_types=1);

namespace App\Fairness;

/**
 * Splits a set of supported/applicable dimensions into PRIMARY and
 * SECONDARY for the two min-max-then-sum phase pairs of GENERATE
 * (docs/allocation-algorithm.md §6/§11). An interface, not a hardcoded
 * split inside ObjectivePhaseFactory, so a future per-team override (e.g.
 * reading a real classification out of PlanningRuleSet.configuration, once
 * that configuration actually has a documented schema for it — it does not
 * today, docs/decisions.md D086) can replace DefaultFairnessDimensionClassifier
 * without touching phase construction itself.
 */
interface FairnessDimensionClassifier
{
    /**
     * @param list<FairnessDimensionKey> $dimensions
     *
     * @return list<FairnessDimensionKey> subset of $dimensions
     */
    public function primaryDimensions(array $dimensions): array;

    /**
     * @param list<FairnessDimensionKey> $dimensions
     *
     * @return list<FairnessDimensionKey> subset of $dimensions
     */
    public function secondaryDimensions(array $dimensions): array;
}
