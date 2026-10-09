<?php

declare(strict_types=1);

namespace App\Entity;

/**
 * Where one swap email stands — the same delivery cycle as the publication
 * outbox (docs/decisions.md D172, PublicationNotificationStatus):
 * PENDING → SENDING (claimed by exactly one sender) → SENT, or FAILED and
 * retried until DutySwapNotification::MAX_ATTEMPTS; CANCELLED when the
 * recipient's account was deactivated meanwhile. SENT means the SMTP
 * server accepted the message — never a proof it was delivered.
 */
enum DutySwapNotificationStatus: string
{
    case PENDING = 'PENDING';
    case SENDING = 'SENDING';
    case SENT = 'SENT';
    case FAILED = 'FAILED';
    case CANCELLED = 'CANCELLED';
}
