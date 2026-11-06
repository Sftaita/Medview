<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AvailabilityCollection;

/**
 * One reminder of the dashboard (docs/decisions.md D234): an open collection
 * and the days of it that still wait for the user's availabilities.
 */
final readonly class CollectionReminder
{
    /** At least one of the lines is not generated yet. */
    public const NOT_GENERATED = 'NOT_GENERATED';
    /** Only the new dates of an extension, still without holder (D226). */
    public const NEW_DATES = 'NEW_DATES';

    /**
     * @param list<string> $lineNames the lines of the user still waiting, by name
     */
    public function __construct(
        public AvailabilityCollection $collection,
        public \DateTimeImmutable $from,
        /** Exclusive. */
        public \DateTimeImmutable $end,
        public array $lineNames,
        public string $reason,
    ) {
    }

    /**
     * @return array{from: string, lastDay: string, lines: list<string>, reason: string}
     */
    public function toArray(): array
    {
        return [
            'from' => $this->from->format('Y-m-d'),
            'lastDay' => $this->end->modify('-1 day')->format('Y-m-d'),
            'lines' => $this->lineNames,
            'reason' => $this->reason,
        ];
    }
}
