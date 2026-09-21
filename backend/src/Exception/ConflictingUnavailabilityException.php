<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * "I have no unavailability on this period" contradicts at least one
 * UNAVAILABLE period the person already saved inside the window
 * (docs/availability-collection.md §3).
 */
final class ConflictingUnavailabilityException extends \RuntimeException
{
    public function __construct(public readonly int $unavailablePeriodCount)
    {
        parent::__construct(sprintf('You still have %d unavailability period(s) in this window: confirm your availabilities instead, or remove them first.', $unavailablePeriodCount));
    }
}
