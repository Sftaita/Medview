<?php

declare(strict_types=1);

namespace App\Service;

/**
 * One statistics perimeter — the selected period, the published cumulative
 * or the provisional cumulative — with the exact date bounds it covers and
 * the planning periods it sums, always shown explicitly (docs/decisions.md
 * D132 §41, D201: never a bare "global" without saying what counts).
 */
final readonly class StatisticsScope
{
    /**
     * @param list<StatisticsGroup> $groups
     * @param list<int>             $periodNumbers the planning periods at least one group sums
     */
    public function __construct(
        public \DateTimeImmutable $startsAt,
        public \DateTimeImmutable $endsAt,
        public array $groups,
        public array $periodNumbers = [],
        public ?int $throughPeriodNumber = null,
    ) {
    }
}
