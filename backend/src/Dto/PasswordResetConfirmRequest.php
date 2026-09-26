<?php

declare(strict_types=1);

namespace App\Dto;

use App\Validator\PasswordPolicy;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * POST /api/password-reset/confirm payload. $token is the raw value from
 * the email link's fragment (#token=...) — never validated by shape here,
 * only by PasswordResetService actually finding a usable row for its hash.
 */
final class PasswordResetConfirmRequest
{
    #[Assert\NotBlank]
    public string $token = '';

    #[Assert\NotBlank]
    #[Assert\Length(min: PasswordPolicy::MIN_LENGTH, minMessage: PasswordPolicy::MIN_LENGTH_MESSAGE)]
    public string $newPassword = '';
}
