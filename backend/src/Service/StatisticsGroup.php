<?php

declare(strict_types=1);

namespace App\Service;

/**
 * One PlanningLine's member rows (docs/decisions.md D132) — the same
 * grouping principle as the rest of the calendar (never mixing populations
 * across lines, docs/planning.md), reused for both statistics scopes.
 */
final readonly class StatisticsGroup
{
    /**
     * @param list<StatisticsMemberRow> $members
     */
    public function __construct(
        public string $groupStableId,
        public string $groupLabel,
        public array $members,
    ) {
    }
}
