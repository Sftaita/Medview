<?php

declare(strict_types=1);

namespace App\Service;

/**
 * What the pre-generation check can tell an OWNER/ADMIN (docs/decisions.md D129).
 *
 * BLOCKERS are technical impossibilities the generation pipeline would itself
 * refuse (no active rule set, no duty to place, a published period…) — never
 * an organisational signal. Nothing about members who have not confirmed nor
 * about a passed deadline is ever a blocker: those are WARNINGS, and the
 * generation stays possible.
 */
enum PreflightIssueCode: string
{
    // --- blockers -------------------------------------------------------
    /** The line's team has never activated a PlanningRuleSet: PlanningSnapshotService cannot snapshot it. */
    case NO_ACTIVE_RULE_SET = 'NO_ACTIVE_RULE_SET';

    /** The line's period has no Duty: there is nothing to distribute. */
    case NO_DUTIES = 'NO_DUTIES';

    /**
     * The line's period is VALIDATED, PUBLISHED or ARCHIVED: a new generation would silently supersede a published
     * planning (D125). VALIDATED included since lot L3 (docs/planning-cycles.md I8).
     */
    case PERIOD_LOCKED = 'PERIOD_LOCKED';

    /** The system was never seeded with a SolverParameterSet. */
    case NO_SOLVER_PARAMETER_SET = 'NO_SOLVER_PARAMETER_SET';

    /**
     * docs/decisions.md D163: a day of a conditional line matches several
     * duties of its source line on that calendar day — no coverage source
     * is ever chosen arbitrarily, the day is left unmaterialized.
     */
    case AMBIGUOUS_COVERAGE_SOURCE = 'AMBIGUOUS_COVERAGE_SOURCE';

    /**
     * docs/planning-cycles.md §5.6 (lot L3): a conditional line whose source
     * line has no period in the period generated — its reinforcements would
     * depend on nothing; nothing is materialized for it.
     */
    case CONDITIONAL_SOURCE_NOT_IN_PERIOD = 'CONDITIONAL_SOURCE_NOT_IN_PERIOD';

    /**
     * docs/planning-cycles.md §5.1 (lot L3): a boundary between this period
     * and a neighbouring one cuts one of the line's blocks — that occurrence
     * would be materialized in neither period (PeriodBoundaryGuard).
     */
    case BLOCK_CUT_BY_PERIOD_BOUNDARY = 'BLOCK_CUT_BY_PERIOD_BOUNDARY';

    /**
     * docs/decisions.md D195 (lot L4b): the line is the source of a
     * conditional line whose period is validated, published or archived — a
     * new result for it would change the demand already published for that
     * reinforcement line. Never regenerated while it is so.
     */
    case SOURCE_OF_PUBLISHED_LINE = 'SOURCE_OF_PUBLISHED_LINE';

    /**
     * docs/decisions.md D195 (lot L4b): no line of the period is to be
     * generated — every line that counts is kept (locked) or there is none
     * (nothing demanded anywhere). No job is ever queued for nothing.
     */
    case NO_LINE_TO_GENERATE = 'NO_LINE_TO_GENERATE';

    /**
     * docs/decisions.md D195 (lot L4b): duties are demanded of the line in the
     * period but none of its team's memberships intersects it — the line is
     * INCOMPLETE (LineApplicability, D194), its generation is refused instead
     * of producing a calendar nobody can hold. Replaces the warning
     * LINE_WITHOUT_MEMBERS.
     */
    case NO_MEMBER_IN_PERIOD = 'NO_MEMBER_IN_PERIOD';

    // --- warnings -------------------------------------------------------
    /**
     * docs/decisions.md D192: the start of this period is a historical
     * boundary (the end of a locked previous period) that cuts one of the
     * line's blocks — the occurrence's days in this period are a remainder
     * block, its earlier days stay without duty in the previous period.
     */
    case HISTORICAL_BLOCK_REMAINDER = 'HISTORICAL_BLOCK_REMAINDER';

    /** Some participants have not confirmed their availabilities. */
    case PENDING_MEMBERS = 'PENDING_MEMBERS';

    /** The informative availability deadline is passed. */
    case DEADLINE_PASSED = 'DEADLINE_PASSED';

    /**
     * No longer produced since lot L3 (docs/planning-cycles.md I8: a VALIDATED period is PERIOD_LOCKED) — kept so a
     * client knowing it still parses the enum. VALIDATED only exists inside a publication's transaction anyway.
     */
    case VALIDATION_WILL_BE_INVALIDATED = 'VALIDATION_WILL_BE_INVALIDATED';

    /**
     * No longer produced since lot L4b (docs/decisions.md D195: a line to generate without any member is the blocker
     * NO_MEMBER_IN_PERIOD) — kept so a client knowing it still parses the enum.
     */
    case LINE_WITHOUT_MEMBERS = 'LINE_WITHOUT_MEMBERS';

    /**
     * docs/decisions.md D163: some days (or whole blocks) of a conditional
     * line have no duty on the source line that day — no reinforcement can
     * exist then, those days are not materialized.
     */
    case COVERAGE_SOURCE_MISSING = 'COVERAGE_SOURCE_MISSING';

    public function isBlocker(): bool
    {
        return \in_array($this, [self::NO_ACTIVE_RULE_SET, self::NO_DUTIES, self::PERIOD_LOCKED, self::NO_SOLVER_PARAMETER_SET, self::AMBIGUOUS_COVERAGE_SOURCE, self::CONDITIONAL_SOURCE_NOT_IN_PERIOD, self::BLOCK_CUT_BY_PERIOD_BOUNDARY, self::SOURCE_OF_PUBLISHED_LINE, self::NO_LINE_TO_GENERATE, self::NO_MEMBER_IN_PERIOD], true);
    }
}
