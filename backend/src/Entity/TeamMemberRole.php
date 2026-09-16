<?php

declare(strict_types=1);

namespace App\Entity;

/**
 * Team-scoped role (D012): deliberately separate from User::getRoles(),
 * which only ever carries the global ROLE_USER. Authorization within a
 * team is checked via dedicated Voters reading TeamMember::role, never by
 * feeding these values into Symfony Security's global role system.
 */
enum TeamMemberRole: string
{
    case OWNER = 'OWNER';
    case ADMIN = 'ADMIN';
    case MEMBER = 'MEMBER';
}
