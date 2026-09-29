<?php

declare(strict_types=1);

namespace App\Calendar;

/**
 * One all-day event of an iCalendar feed (docs/decisions.md D170): from
 * $firstDay to $lastDay, both inclusive — the RFC 5545 exclusive DTEND is
 * IcsWriter's business, never its callers'.
 */
final readonly class IcsEvent
{
    public function __construct(
        public string $uid,
        public \DateTimeImmutable $firstDay,
        public \DateTimeImmutable $lastDay,
        public string $summary,
        public string $description,
        public bool $tentative = false,
        public ?string $url = null,
    ) {
        if ($lastDay->format('Y-m-d') < $firstDay->format('Y-m-d')) {
            throw new \InvalidArgumentException('An event cannot end before it starts.');
        }
    }
}
