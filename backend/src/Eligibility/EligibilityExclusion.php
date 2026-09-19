<?php

declare(strict_types=1);

namespace App\Eligibility;

/**
 * One reason a (DutyUnit, candidate) pair is excluded. $tier is always
 * derived from $reason — never accepted independently — so it is
 * structurally impossible to construct an exclusion whose tier
 * contradicts ExclusionReason::tier() (docs/eligibility.md).
 *
 * $context is a small structured payload for future audit (never a free
 * "why" string as the source of truth) — its shape varies by $reason; see
 * EligibilityService for what each reason actually puts there.
 */
final readonly class EligibilityExclusion
{
    public ConstraintTier $tier;

    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        public ExclusionReason $reason,
        public array $context = [],
    ) {
        $this->tier = $reason->tier();
    }
}
