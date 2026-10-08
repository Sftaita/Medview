<?php

declare(strict_types=1);

namespace App\Entity;

/**
 * SUCCESS: the action took effect. DENIED: it was refused by a rule (wrong
 * password confirmation, acting on oneself, last administrator…) — recorded
 * because a refused sensitive attempt is itself worth auditing.
 * FAILURE: reserved for an action that was allowed but could not complete.
 */
enum PlatformAuditOutcome: string
{
    case SUCCESS = 'SUCCESS';
    case DENIED = 'DENIED';
    case FAILURE = 'FAILURE';
}
