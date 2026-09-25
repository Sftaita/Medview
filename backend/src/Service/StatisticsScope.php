<?php

declare(strict_types=1);

namespace App\Service;

/**
 * One statistics perimeter — "this period" or "cumulative" — with the
 * exact date bounds it actually covers, always shown explicitly
 * (docs/decisions.md D132 §41: never a bare "global" without saying what
 * counts).
 */
final readonly class StatisticsScope
{
    /**
     * @param list<StatisticsGroup> $groups
     */
    public function __construct(
        public \DateTimeImmutable $startsAt,
        public \DateTimeImmutable $endsAt,
        public array $groups,
    ) {
    }
}
