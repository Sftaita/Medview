<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\UserAvailabilityPeriod;

/**
 * A personal calendar entry just written (or just deleted), and what the
 * change meets without changing it (docs/decisions.md D216, D218): the
 * generated periods whose days it makes unavailable or available again, the
 * duties held on the days added. Empty when nothing generated is met — never
 * a false alarm.
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
