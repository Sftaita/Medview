<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\Security\Core\User\UserInterface;

/**
 * The identity of another application calling MedVue machine-to-machine
 * (docs/surgicalhub-integration.md §3.1) — never a MedVue User, never stored.
 * Its only role opens the /api/integrations/<name>/ routes and nothing else.
 */
final class IntegrationClient implements UserInterface
{
    public function __construct(
        private readonly string $name,
        private readonly string $role,
    ) {
    }

    public function getUserIdentifier(): string
    {
        return 'integration:'.$this->name;
    }

    public function getRoles(): array
    {
        return [$this->role];
    }

    public function eraseCredentials(): void
    {
    }
}
