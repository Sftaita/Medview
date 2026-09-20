<?php

declare(strict_types=1);

namespace App\Entity;

/**
 * Persisted lifecycle of a TeamInvitation. EXPIRED is set lazily (whenever
 * a PENDING row is touched after its expiresAt); whether an invitation is
 * *usable* is always decided by TeamInvitation::isUsableAt(), never by
 * trusting a possibly stale persisted PENDING.
 */
enum TeamInvitationStatus: string
{
    case PENDING = 'PENDING';
    case ACCEPTED = 'ACCEPTED';
    case EXPIRED = 'EXPIRED';
    case REVOKED = 'REVOKED';
}
