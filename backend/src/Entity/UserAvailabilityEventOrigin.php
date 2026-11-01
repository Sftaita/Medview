<?php

declare(strict_types=1);

namespace App\Entity;

/**
 * Who made a personal calendar change (docs/decisions.md D215): the person
 * from their calendar, or the SurgicalHub synchronisation (no human actor).
 */
enum UserAvailabilityEventOrigin: string
{
    case USER = 'USER';
    case SURGICAL_HUB_SYNC = 'SURGICAL_HUB_SYNC';
}
