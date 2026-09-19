<?php

declare(strict_types=1);

namespace App\Fairness;

/**
 * One DutyUnit left unassigned in `OptimizationResult::$unassignedDuties`
 * (docs/allocation-algorithm.md §21). Deliberately narrower than the full
 * future shape (`{dutyUnitId, criticality, candidateExclusions}`) —
 * `candidateExclusions` belongs to the full UNSAT diagnostic model
 * (docs/allocation-algorithm.md §16), explicitly out of scope for this lot
 * (docs/planning-solver.md).
 */
final readonly class UnassignedDutyUnit
{
    public function __construct(
        public string $dutyUnitStableKey,
        public bool $critical,
    ) {
    }
}
