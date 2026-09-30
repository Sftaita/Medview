<?php

declare(strict_types=1);

namespace App\Entity;

/**
 * Where one publication email stands (docs/decisions.md D172):
 * - PENDING: recorded with its publication, never attempted yet (a crash right after the commit leaves it
 *   here — the retry command picks it up);
 * - SENDING: claimed by one sender, which alone may send it (never two senders for one email);
 * - SENT: the transport accepted it — never sent again;
 * - FAILED: the last attempt failed — retried until PlanningPublicationNotification::MAX_ATTEMPTS;
 * - CANCELLED: the account was deactivated before the email could go out — never emailed.
 */
enum PublicationNotificationStatus: string
{
    case PENDING = 'PENDING';
    case SENDING = 'SENDING';
    case SENT = 'SENT';
    case FAILED = 'FAILED';
    case CANCELLED = 'CANCELLED';
}
