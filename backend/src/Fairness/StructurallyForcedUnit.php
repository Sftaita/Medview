<?php

declare(strict_types=1);

namespace App\Fairness;

use App\Eligibility\DutyUnit;

/**
 * One DutyUnit whose only HARD-eligible candidate is $sourceUserStableId
 * (docs/allocation-algorithm.md §4.2 STRUCTURALLY_FORCED,
 * docs/fairness.md). Local, pre-solve, exhaustive — no solver involved.
 * Deliberately does not model GLOBALLY_FORCED (§4.3): that requires
 * `PlanningSolver.checkFeasibility()`, which does not exist yet and is
 * explicitly out of scope for this lot.
 */
final readonly class StructurallyForcedUnit
{
    public function __construct(
        public DutyUnit $dutyUnit,
        public string $sourceUserStableId,
    ) {
    }
}
