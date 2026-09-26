<?php

declare(strict_types=1);

namespace App\Security;

/**
 * Never exposed to the client (same generic "invalid or expired" response
 * for every case, docs/authentication.md) — kept internally so tests and
 * logs can distinguish *why* a confirm attempt failed.
 */
enum PasswordResetFailureReason: string
{
    case NOT_FOUND = 'not_found';
    case EXPIRED = 'expired';
    /** Consumed by an earlier, already-successful reset. */
    case CONSUMED = 'consumed';
    /** Superseded by a newer request before it was ever used. */
    case REVOKED = 'revoked';
    /** The token is otherwise usable, but the account is no longer eligible (deactivated since the request). */
    case ACCOUNT_NOT_ELIGIBLE = 'account_not_eligible';
}
