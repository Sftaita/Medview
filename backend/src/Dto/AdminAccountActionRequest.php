<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Body of POST /api/admin/users/{id}/deactivate|reactivate|revoke-sessions:
 * an optional reason, kept in the audit entry. Nothing else is accepted —
 * the target comes from the URL, the actor from the session.
 */
final class AdminAccountActionRequest
{
    #[Assert\Length(max: 500)]
    public ?string $reason = null;
}
