<?php

declare(strict_types=1);

namespace App\Service\SurgicalHub;

enum SurgicalHubSyncStatus: string
{
    /** A complete answer was reconciled (possibly with nothing to change). */
    case SYNCED = 'SYNCED';
    /** Nothing local was written; the previous imports stay as they were. */
    case FAILED = 'FAILED';
    /** SurgicalHub revoked the association (`410`): revoked locally (D9 applied). */
    case REVOKED = 'REVOKED';
    /** SurgicalHub says it never knew the association (`404`): suspended, nothing deleted (§9). */
    case SUSPENDED = 'SUSPENDED';
    /** The association was not active (anymore): nothing to do. */
    case INACTIVE = 'INACTIVE';
}
