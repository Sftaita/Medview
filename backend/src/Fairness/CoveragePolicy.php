<?php

declare(strict_types=1);

namespace App\Fairness;

/**
 * docs/allocation-algorithm.md §21's `coveragePolicy: { requireStrictFirst,
 * criticalDutyUnits }`. `$criticalDutyUnits` has a real, already-modeled
 * data source — `Duty::getCriticality()` (docs/allocation-algorithm.md
 * §10) — so it is populated for real, never left as a placeholder.
 * `$requireStrictFirst` is always true for GENERATE in this lot (the
 * strict/partial diagnostic solve of §10 is not implemented yet — this
 * field only records the policy the future solver must honor).
 */
final readonly class CoveragePolicy
{
    /**
     * @param list<string> $criticalDutyUnitStableKeys
     */
    public function __construct(
        public bool $requireStrictFirst,
        public array $criticalDutyUnitStableKeys,
    ) {
    }
}
