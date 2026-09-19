<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * A User may have at most one open (unended) PlanningTeamMember stint at a
 * time within the same Planning — not across the whole application
 * (docs/decisions.md D080, replacing the app-wide D072 rule). They may
 * simultaneously hold an open membership in a PlanningTeam of a *different*
 * Planning, may have been a member of a different PlanningTeam of this
 * Planning in the past, and may join another one of this Planning's teams
 * once their current membership here is closed — just never two open at
 * once within the same Planning.
 */
final class PlanningTeamMembershipConflictException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('This User already has an open membership in another PlanningTeam of this Planning — a User may only have one open membership per Planning at a time.');
    }
}
