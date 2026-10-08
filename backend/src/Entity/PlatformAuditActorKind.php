<?php

declare(strict_types=1);

namespace App\Entity;

/**
 * Who performed an audited action: a signed-in User, the server console
 * (`app:platform-admin:*`, run by whoever operates the server), or the
 * system itself.
 */
enum PlatformAuditActorKind: string
{
    case USER = 'USER';
    case CONSOLE = 'CONSOLE';
    case SYSTEM = 'SYSTEM';
}
