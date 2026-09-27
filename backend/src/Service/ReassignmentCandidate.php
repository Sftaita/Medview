<?php

declare(strict_types=1);

namespace App\Service;

/**
 * One PlanningTeamMember who can really take the block right now
 * (docs/decisions.md D144): only candidates with no blocking reason at all
 * are ever built — an impossible candidate is absent from the list, never
 * shown disabled (supersedes D131's "whole pool with reasons" on explicit
 * product request). The server still revalidates the chosen one at save
 * time; this list is never an authority.
 */
final readonly class ReassignmentCandidate
{
    public function __construct(
        public string $teamMemberStableId,
        public string $firstName,
        public string $lastName,
    ) {
    }
}
