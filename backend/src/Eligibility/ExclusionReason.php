<?php

declare(strict_types=1);

namespace App\Eligibility;

/**
 * The full stable contract of exclusion codes from docs/allocation-algorithm.md
 * §3 — not all of them are actually produced by EligibilityService yet
 * (see docs/eligibility.md "Raisons calculables vs déclarées"). A code's
 * $tier() and structural-opportunity effect are fixed here, once, and
 * never vary by context — exactly the guarantee D036 already established
 * for LEGAL_MIN_REST/TEAM_MIN_REST.
 */
enum ExclusionReason: string
{
    case USER_INACTIVE = 'USER_INACTIVE';
    case NOT_TEAM_MEMBER = 'NOT_TEAM_MEMBER';
    case MEMBERSHIP_OUT_OF_RANGE = 'MEMBERSHIP_OUT_OF_RANGE';
    case UNAVAILABLE = 'UNAVAILABLE';
    case NON_PARTICIPATION = 'NON_PARTICIPATION';
    case CONFLICT = 'CONFLICT';

    // Cross-line constraints on the same person (docs/decisions.md D161):
    // the candidate's User already holds a duty on *another* line of the
    // Planning. A local exclusion (the edge is removed), never a global
    // CP-SAT constraint: the other line's duty is already decided.
    case CROSS_LINE_CONFLICT = 'CROSS_LINE_CONFLICT';
    case CROSS_LINE_LEGAL_MIN_REST = 'CROSS_LINE_LEGAL_MIN_REST';
    case CROSS_LINE_TEAM_MIN_REST = 'CROSS_LINE_TEAM_MIN_REST';
    // Prepared for conditional coverage (D161): the candidate would
    // reinforce their own duty. Not produced until conditional duties
    // (Duty.coverageSource) exist.
    case SELF_COVERAGE = 'SELF_COVERAGE';

    // Declared per the spec's stable contract, not yet computed by
    // EligibilityService — see docs/eligibility.md for why each one is
    // deferred (missing data, or a rule not yet modeled).
    case SITE_NOT_ALLOWED = 'SITE_NOT_ALLOWED';
    case MISSING_SKILL = 'MISSING_SKILL';
    case LEGAL_MIN_REST = 'LEGAL_MIN_REST';
    case LOCK_CONFLICT = 'LOCK_CONFLICT';
    case GROUP_UNAVAILABLE = 'GROUP_UNAVAILABLE';
    case TEAM_MIN_REST = 'TEAM_MIN_REST';
    case MAX_DUTIES = 'MAX_DUTIES';
    case MAX_WEEKENDS = 'MAX_WEEKENDS';
    case MAX_CONSECUTIVE_NIGHTS = 'MAX_CONSECUTIVE_NIGHTS';
    case RULE_EXCLUSION = 'RULE_EXCLUSION';

    /**
     * Fixed tier per docs/allocation-algorithm.md §3 — copied verbatim,
     * never reconfigurable per team/context.
     */
    public function tier(): ConstraintTier
    {
        return match ($this) {
            self::USER_INACTIVE,
            self::NOT_TEAM_MEMBER,
            self::MEMBERSHIP_OUT_OF_RANGE,
            self::UNAVAILABLE,
            self::SITE_NOT_ALLOWED,
            self::MISSING_SKILL,
            self::CONFLICT,
            self::LEGAL_MIN_REST,
            self::LOCK_CONFLICT,
            self::GROUP_UNAVAILABLE,
            self::CROSS_LINE_CONFLICT,
            self::CROSS_LINE_LEGAL_MIN_REST,
            self::SELF_COVERAGE,
            self::NON_PARTICIPATION => ConstraintTier::HARD,

            self::TEAM_MIN_REST,
            self::CROSS_LINE_TEAM_MIN_REST,
            self::MAX_DUTIES,
            self::MAX_WEEKENDS,
            self::MAX_CONSECUTIVE_NIGHTS,
            self::RULE_EXCLUSION => ConstraintTier::POLICY_HARD,
        };
    }

    /**
     * Whether this reason zeroes structuralOpportunity (docs/eligibility.md
     * §structuralOpportunity) rather than merely making the (dutyUnit,
     * member) pair ineligible. EligibilityService only ever actually
     * *reaches* this for USER_INACTIVE/MEMBERSHIP_OUT_OF_RANGE/
     * NON_PARTICIPATION/UNAVAILABLE in this lot — the rest are recorded
     * for contract completeness, matching the future intent already
     * written in docs/allocation-algorithm.md §5 (site/skill are
     * structural; a temporal/policy constraint like rest or workload caps
     * is not), but are not exercised by any test since nothing produces
     * them yet.
     */
    public function zerosStructuralOpportunity(): bool
    {
        return match ($this) {
            self::NOT_TEAM_MEMBER,
            self::MEMBERSHIP_OUT_OF_RANGE,
            self::NON_PARTICIPATION,
            self::USER_INACTIVE,
            self::SITE_NOT_ALLOWED,
            self::MISSING_SKILL => true,

            // A personal declaration (UNAVAILABLE) or a circumstance of
            // this specific generation/solve (CONFLICT, LOCK_CONFLICT,
            // rest/workload policy limits) never redefines the member's
            // theoretical structural exposure — only administrative/team
            // facts do (docs/availability.md §2, the resistance-to-gaming
            // rule this lot's tests exercise directly).
            self::UNAVAILABLE,
            self::CONFLICT,
            self::LEGAL_MIN_REST,
            self::LOCK_CONFLICT,
            // A duty held on another line is a circumstance of the current
            // calendar, not a structural fact about this line: exactly like
            // CONFLICT within a line, it never shrinks the person's exposure
            // (docs/decisions.md D161).
            self::CROSS_LINE_CONFLICT,
            self::CROSS_LINE_LEGAL_MIN_REST,
            self::CROSS_LINE_TEAM_MIN_REST,
            self::SELF_COVERAGE,
            // Derived reason: its real structural effect is computed from
            // the wrapped root cause(s) before wrapping, never from this
            // method — see EligibilityService.
            self::GROUP_UNAVAILABLE,
            self::TEAM_MIN_REST,
            self::MAX_DUTIES,
            self::MAX_WEEKENDS,
            self::MAX_CONSECUTIVE_NIGHTS,
            self::RULE_EXCLUSION => false,
        };
    }
}
