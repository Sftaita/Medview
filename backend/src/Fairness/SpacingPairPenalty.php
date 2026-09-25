<?php

declare(strict_types=1);

namespace App\Fairness;

/**
 * One real reason two `DutyUnit`s should not both go to the same
 * candidate (docs/decisions.md D139) — `$penalty` is an ordinal weight
 * local to the `SPACING_SCORE` phase only, never mixed with a fairness
 * dimension's scale (D032: no global weighted score).
 */
final readonly class SpacingPairPenalty
{
    public function __construct(
        public string $unitAKey,
        public string $unitBKey,
        public int $penalty,
    ) {
    }
}
