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

    /** The line's period is PUBLISHED or ARCHIVED: a new generation would silently supersede a published planning (D125). */
    case PERIOD_LOCKED = 'PERIOD_LOCKED';

    /** The system was never seeded with a SolverParameterSet. */
    case NO_SOLVER_PARAMETER_SET = 'NO_SOLVER_PARAMETER_SET';

    // --- warnings -------------------------------------------------------
    /** Some participants have not confirmed their availabilities. */
    case PENDING_MEMBERS = 'PENDING_MEMBERS';

    /** The informative availability deadline is passed. */
    case DEADLINE_PASSED = 'DEADLINE_PASSED';

    /** The line's period is VALIDATED: generating again sends it back to GENERATED, to be re-validated. */
    case VALIDATION_WILL_BE_INVALIDATED = 'VALIDATION_WILL_BE_INVALIDATED';

    /** The line has no participant for the period: its duties cannot be covered. */
    case LINE_WITHOUT_MEMBERS = 'LINE_WITHOUT_MEMBERS';

    public function isBlocker(): bool
    {
        return \in_array($this, [self::NO_ACTIVE_RULE_SET, self::NO_DUTIES, self::PERIOD_LOCKED, self::NO_SOLVER_PARAMETER_SET], true);
    }
}
