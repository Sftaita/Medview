<?php

declare(strict_types=1);

namespace App\Entity;

/**
 * What a platform audit entry records (docs/admin.md §6). Kept in sync with
 * the CHECK constraint of platform_audit_events: adding a case needs a
 * migration.
 */
enum PlatformAuditEventType: string
{
    case USER_REGISTERED = 'USER_REGISTERED';
    case USER_DEACTIVATED = 'USER_DEACTIVATED';
    case USER_REACTIVATED = 'USER_REACTIVATED';
    case USER_SESSIONS_REVOKED = 'USER_SESSIONS_REVOKED';
    case PLATFORM_ADMIN_GRANTED = 'PLATFORM_ADMIN_GRANTED';
    case PLATFORM_ADMIN_REVOKED = 'PLATFORM_ADMIN_REVOKED';
    case PASSWORD_RESET_COMPLETED = 'PASSWORD_RESET_COMPLETED';
}
