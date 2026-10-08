<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Body of POST /api/admin/platform-admins: the existing account to promote,
 * and the acting administrator's own password, re-typed.
 */
final class GrantPlatformAdminRequest
{
    #[Assert\NotBlank]
    #[Assert\Email]
    #[Assert\Length(max: 180)]
    public string $email = '';

    #[Assert\NotBlank]
    #[Assert\Length(max: 4096)]
    public string $password = '';
}
