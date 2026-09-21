<?php

declare(strict_types=1);

namespace App\Entity;

/**
 * Derived from AvailabilityCollectionResponse's own columns, never stored:
 * ACKNOWLEDGED once acknowledgedAt is set (it wins over everything else),
 * WITHDRAWN when the person left the planning before answering (no longer
 * expected, excluded from the X/Y counters), PENDING otherwise.
 */
enum AvailabilityResponseStatus: string
{
    case PENDING = 'PENDING';
    case ACKNOWLEDGED = 'ACKNOWLEDGED';
    case WITHDRAWN = 'WITHDRAWN';
}
