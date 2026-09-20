<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Input of POST /api/plannings/{planning}/teams/{team}/invitations.
 * firstName/lastName are only suggestions for the invitee, who can correct
 * them when creating their account. There is deliberately no role field
 * (always MEMBER in v1) and no way to pass an existing user's identifier:
 * the address alone decides between "add existing user" and "invite".
 */
final class InviteToTeamRequest
{
    #[Assert\NotBlank]
    #[Assert\Email]
    #[Assert\Length(max: 180)]
    public string $email = '';

    #[Assert\NotBlank]
    #[Assert\Length(max: 100)]
    public string $firstName = '';

    #[Assert\NotBlank]
    #[Assert\Length(max: 100)]
    public string $lastName = '';
}
