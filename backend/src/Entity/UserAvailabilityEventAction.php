<?php

declare(strict_types=1);

namespace App\Entity;

/**
 * What happened to one entry of a personal calendar (docs/decisions.md D215).
 */
enum UserAvailabilityEventAction: string
{
    case CREATED = 'CREATED';
    /** Its dates, its type, or both changed. */
    case RESCHEDULED = 'RESCHEDULED';
    case DELETED = 'DELETED';
}
