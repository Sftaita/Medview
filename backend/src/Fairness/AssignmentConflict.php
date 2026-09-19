<?php

declare(strict_types=1);

namespace App\Fairness;

use App\Eligibility\ConstraintTier;
use App\Eligibility\ExclusionReason;

/**
 * A global constraint linking exactly two `DutyUnit` for exactly one
 * candidate: `x[left,candidate] + x[right,candidate] <= 1`
 * (docs/planning-solver.md §Contraintes globales, docs/decisions.md
 * D100). Distinct from an `EligibilityExclusion`, which removes a single
 * `(DutyUnit, candidate)` edge entirely — this links *two* otherwise
 * eligible edges to each other. `$reason` is always `CONFLICT` (HARD,
 * physical time overlap), `LEGAL_MIN_REST` (HARD, docs/decisions.md
 * D105 — HARD only once the per-generation option enabling it is on),
 * or `TEAM_MIN_REST` (POLICY_HARD, insufficient rest) — `$tier` is
 * derived from it, never accepted independently, same pattern as
 * `EligibilityExclusion::$tier`.
 *
 * `$leftDutyUnitStableKey`/`$rightDutyUnitStableKey` are always ordered
 * (`left < right` by string comparison) — a canonical, deterministic
 * representation regardless of which unit `AssignmentConflictAnalyzer`
 * happened to visit first.
 */
final readonly class AssignmentConflict
{
    private const ALLOWED_REASONS = [ExclusionReason::CONFLICT, ExclusionReason::LEGAL_MIN_REST, ExclusionReason::TEAM_MIN_REST];

    public ConstraintTier $tier;

    public function __construct(
        public string $candidateStableKey,
        public string $leftDutyUnitStableKey,
        public string $rightDutyUnitStableKey,
        public ExclusionReason $reason,
    ) {
        if (!\in_array($reason, self::ALLOWED_REASONS, true)) {
            throw new \InvalidArgumentException(sprintf('AssignmentConflict only ever represents CONFLICT, LEGAL_MIN_REST, or TEAM_MIN_REST, got %s.', $reason->value));
        }

        $this->tier = $reason->tier();
    }
}
