<?php

declare(strict_types=1);

namespace App\Entity;

/**
 * OPEN → CLOSED, one way only (docs/availability-collection.md §4): a
 * closed collection is frozen history, never reopened — a new window opens
 * a new collection instead. Nothing closes a collection automatically, not
 * even a passed deadline: a late answer is still worth recording.
 */
enum AvailabilityCollectionStatus: string
{
    case OPEN = 'OPEN';
    case CLOSED = 'CLOSED';
}
