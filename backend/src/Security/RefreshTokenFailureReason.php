<?php

declare(strict_types=1);

namespace App\Security;

/**
 * Never exposed to the client (same generic 401 for every case, see
 * docs/authentication.md) — kept internally so tests and logs can
 * distinguish *why* a refresh attempt failed.
 */
enum RefreshTokenFailureReason: string
{
    case NOT_FOUND = 'not_found';
    case EXPIRED = 'expired';
    /** Explicitly revoked (e.g. via logout), not via rotation. */
    case REVOKED = 'revoked';
    /** Presented again after already being consumed by a rotation: compromise signal. */
    case REUSED = 'reused';
    case ACCOUNT_DISABLED = 'account_disabled';
}
