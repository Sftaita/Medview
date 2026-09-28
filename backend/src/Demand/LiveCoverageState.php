<?php

declare(strict_types=1);

namespace App\Demand;

/**
 * Where one conditional unit of the current calendar stands (docs/decisions.md
 * D165): its LIVE demand (required / not required / undetermined) crossed
 * with its current coverage — computed once here so no consumer (API,
 * preflight, frontend) ever re-derives it.
 *
 * - REQUIRED_ASSIGNED: triggered and covered — nothing to do.
 * - REQUIRED_UNASSIGNED: triggered, nobody holds it — action needed.
 * - NOT_REQUIRED_UNASSIGNED: not triggered, nobody holds it — normal.
 * - NOT_REQUIRED_ASSIGNED: not triggered any more, somebody still holds it
 *   — a superfluous reinforcement, kept on purpose (a real load), warned about.
 * - UNDETERMINED: the demand cannot be evaluated (a source duty nobody
 *   holds) — never read as "not required", whether or not someone holds it.
 */
enum LiveCoverageState: string
{
    case REQUIRED_ASSIGNED = 'REQUIRED_ASSIGNED';
    case REQUIRED_UNASSIGNED = 'REQUIRED_UNASSIGNED';
    case NOT_REQUIRED_UNASSIGNED = 'NOT_REQUIRED_UNASSIGNED';
    case NOT_REQUIRED_ASSIGNED = 'NOT_REQUIRED_ASSIGNED';
    case UNDETERMINED = 'UNDETERMINED';

    public static function of(UnitDemand|DutyDemand $demand, bool $assigned): self
    {
        if (!$demand->determined) {
            return self::UNDETERMINED;
        }
        if ($demand->required) {
            return $assigned ? self::REQUIRED_ASSIGNED : self::REQUIRED_UNASSIGNED;
        }

        return $assigned ? self::NOT_REQUIRED_ASSIGNED : self::NOT_REQUIRED_UNASSIGNED;
    }

    /** true: the demand is known and says "required" (the only state a new assignment may be written for). */
    public function isRequired(): bool
    {
        return self::REQUIRED_ASSIGNED === $this || self::REQUIRED_UNASSIGNED === $this;
    }

    /** A reinforcement nobody needs any more, still held by someone: kept, never removed automatically. */
    public function isSuperfluous(): bool
    {
        return self::NOT_REQUIRED_ASSIGNED === $this;
    }
}
