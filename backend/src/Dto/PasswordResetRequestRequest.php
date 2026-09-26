<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * POST /api/password-reset/request payload. Deliberately minimal: the
 * public response never depends on anything but this shape being valid
 * JSON with a well-formed email (docs/authentication.md).
 */
final class PasswordResetRequestRequest
{
    #[Assert\NotBlank]
    #[Assert\Email]
    #[Assert\Length(max: 180)]
    public string $email = '';
}
