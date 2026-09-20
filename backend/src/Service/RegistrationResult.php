<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;

final class RegistrationResult
{
    /**
     * @param list<ConsumedInvitation> $joined teams joined through invitations consumed by this registration (empty for a classic sign-up)
     */
    public function __construct(
        public readonly User $user,
        public readonly array $joined = [],
    ) {
    }
}
