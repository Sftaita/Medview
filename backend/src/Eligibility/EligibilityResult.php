<?php

declare(strict_types=1);

namespace App\Eligibility;

/**
 * The outcome of evaluating one (DutyUnit, PlanningSnapshotMember) pair.
 * $eligible is deliberately derived from $exclusions rather than accepted
 * as an independent constructor argument — the same reasoning as
 * EligibilityExclusion::$tier: a result claiming both "eligible" and a
 * non-empty exclusion list is a bug, not a state this type should be able
 * to represent at all.
 *
 * $preferred is a separate, non-exclusionary signal (docs/eligibility.md
 * §PREFER_DUTY) — PREFER_DUTY must never appear in $exclusions and must
 * never change $eligible or $structuralOpportunity; it exists only so a
 * future PreferenceService/builder does not have to re-query the snapshot
 * itself for what this lot already computed.
 */
final readonly class EligibilityResult
{
    public bool $eligible;

    /**
     * @param list<EligibilityExclusion> $exclusions
     */
    public function __construct(
        public array $exclusions,
        public bool $structuralOpportunity,
        public bool $preferred = false,
    ) {
        $this->eligible = [] === $exclusions;
    }
}
