<?php

declare(strict_types=1);

namespace App\Entity;

/**
 * The two explicit ways to answer a collection (docs/availability-collection.md §3).
 * Both are a real business event, never inferred from the number of
 * UserAvailabilityPeriod rows in the window.
 */
enum AvailabilityAcknowledgementKind: string
{
    /** "My availabilities for this period are up to date" — whatever they contain. */
    case CONFIRMED = 'CONFIRMED';

    /** "I have no unavailability on this period" — refused while an UNAVAILABLE period exists in the window. */
    case NO_UNAVAILABILITY = 'NO_UNAVAILABILITY';
}
