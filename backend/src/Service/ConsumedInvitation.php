<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\PlanningTeamMember;
use App\Entity\TeamInvitation;

/**
 * An invitation that was really consumed, paired with the membership it
 * produced.
 */
final class ConsumedInvitation
{
    public function __construct(
        public readonly TeamInvitation $invitation,
        public readonly PlanningTeamMember $member,
    ) {
    }

    /**
     * What the API tells the person who just joined — team and planning
     * names and identifiers only, nothing about the invitation itself.
     *
     * @return array<string, string>
     */
    public function toArray(): array
    {
        $team = $this->member->getPlanningTeam();
        $planning = $this->member->getPlanning();

        return [
            'teamStableId' => (string) $team->getStableId(),
            'teamName' => $team->getName(),
            'planningStableId' => (string) $planning->getStableId(),
            'planningName' => $planning->getName(),
        ];
    }
}
