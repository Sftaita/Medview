<?php

declare(strict_types=1);

namespace App\Service;

use App\Demand\LiveCoverageState;
use App\Entity\Duty;
use App\Entity\PlanningLine;
use App\Entity\PlanningTeamMember;

/**
 * One Duty of the current calendar and whoever holds it right now
 * (DutyAssignment.current, D131) — null when it is uncovered.
 *
 * `$coverageState` (docs/decisions.md D166): a conditional duty's live
 * state, computed once by CurrentCalendarReader from the LiveDemandView —
 * null for an intrinsic duty. Every output of the current calendar
 * (publication record, republication digest, export) reads the decision
 * from here, never re-deriving the conditional rule.
 */
final readonly class CalendarCell
{
    public function __construct(
        public PlanningLine $line,
        public Duty $duty,
        public ?PlanningTeamMember $member,
        public ?LiveCoverageState $coverageState = null,
    ) {
    }

    /**
     * false only for a conditional duty nobody needs and nobody holds: it is
     * not part of the calendar people see — never a "Non attribué" gap. An
     * intrinsic duty is always shown, as before; a superfluous reinforcement
     * someone still holds is a real assignment, shown.
     */
    public function isShown(): bool
    {
        return LiveCoverageState::NOT_REQUIRED_UNASSIGNED !== $this->coverageState;
    }

    /** A reinforcement whose demand cannot be evaluated (its source duty has no holder) — never "not required". */
    public function isUndetermined(): bool
    {
        return LiveCoverageState::UNDETERMINED === $this->coverageState;
    }
}
