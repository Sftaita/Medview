<?php

declare(strict_types=1);

namespace App\Entity;

/** The outbox states of D172, for the period preparation's emails (docs/decisions.md D210). */
enum PreparationDeliveryStatus: string
{
    case PENDING = 'PENDING';
    /** Claimed by exactly one sender. */
    case SENDING = 'SENDING';
    case SENT = 'SENT';
    /** Refused by the transport: retried until MAX_ATTEMPTS. */
    case FAILED = 'FAILED';
    /** No longer relevant when its turn came (answered meanwhile, generation launched, account disabled…). */
    case CANCELLED = 'CANCELLED';
}
