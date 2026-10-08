<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Body of a sensitive platform action that only needs the acting
 * administrator to re-type their password (revoking a platform admin).
 */
final class PasswordConfirmationRequest
{
    #[Assert\NotBlank]
    #[Assert\Length(max: 4096)]
    public string $password = '';
}
