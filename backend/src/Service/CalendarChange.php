<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\UserAvailabilityPeriod;

/**
 * A personal calendar entry just written, and what its new unavailable days
 * meet without changing it (docs/decisions.md D216): the generated periods,
 * the duties held. Empty when nothing generated is met — never a false alarm.
 */
final readonly class CalendarChange
{
    /**
     * @param list<array<string, mixed>> $impacts AvailabilityImpactService::impacts()
     */
    public function __construct(
        public UserAvailabilityPeriod $period,
        public array $impacts,
    ) {
    }
}
