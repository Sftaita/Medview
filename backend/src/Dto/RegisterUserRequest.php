<?php

declare(strict_types=1);

namespace App\Dto;

use App\Validator\ValidPhoneNumber;
use Symfony\Component\Validator\Constraints as Assert;

final class RegisterUserRequest
{
    #[Assert\NotBlank]
    #[Assert\Email]
    #[Assert\Length(max: 180)]
    public string $email = '';

    #[Assert\NotBlank]
    #[Assert\Length(min: 8, minMessage: 'Your password must be at least {{ limit }} characters long.')]
    public string $plainPassword = '';

    #[Assert\NotBlank]
    #[Assert\Length(max: 100)]
    public string $firstName = '';

    #[Assert\NotBlank]
    #[Assert\Length(max: 100)]
    public string $lastName = '';

    /** Any real phone number; stored as E.164 (docs/decisions.md D112). */
    #[Assert\NotBlank]
    #[Assert\Length(max: 40)]
    #[ValidPhoneNumber]
    public string $phone = '';

    /**
     * Raw token from an invitation link. When set, $email must equal the
     * invitation's email and the pending invitations for that address are
     * consumed atomically with the account creation (docs/decisions.md D113).
     */
    public ?string $invitationToken = null;
}
