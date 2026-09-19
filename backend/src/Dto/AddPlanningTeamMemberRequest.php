<?php

declare(strict_types=1);

namespace App\Dto;

use App\Entity\TeamMemberRole;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Input shape for POST /api/plannings/{planningStableId}/teams/{teamStableId}/members.
 * $userStableId is the only client-facing way to designate a candidate —
 * never a name lookup, to avoid ambiguity between homonymous Users.
 */
final class AddPlanningTeamMemberRequest
{
    #[Assert\NotBlank]
    public string $userStableId = '';

    #[Assert\NotBlank]
    #[Assert\Choice(choices: ['OWNER', 'ADMIN', 'MEMBER'])]
    public string $role = 'MEMBER';

    #[Assert\NotBlank]
    public string $membershipStart = '';

    public function roleEnum(): TeamMemberRole
    {
        return TeamMemberRole::from($this->role);
    }
}
