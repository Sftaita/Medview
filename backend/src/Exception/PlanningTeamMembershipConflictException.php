<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * A User may have at most one open (unended) PlanningTeamMember stint at a
 * time in the same PlanningTeam (docs/decisions.md D150). Since D150
 * relaxed D080 they may hold open memberships in several teams (lines) of
 * the same Planning at once, as well as in teams of other Plannings; they
 * may also rejoin a team they left — just never two open stints in one
 * team.
 */
final class PlanningTeamMembershipConflictException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('This User already has an open membership in this PlanningTeam.');
    }
}
