<?php

declare(strict_types=1);

namespace App\Service;

/**
 * One PlanningLine's member rows (docs/decisions.md D132) — the same
 * grouping principle as the rest of the calendar (never mixing populations
 * across lines, docs/planning.md), reused for every statistics scope.
 *
 * Several planning periods (docs/decisions.md D201): `$periodNumbers` are
 * the periods whose duties this group sums, `$publication` the line
 * period's state in the selected period's scope (PUBLISHED or UNPUBLISHED —
 * the latter is provisional, never part of the published cumulative).
 */
final readonly class StatisticsGroup
{
    public const PUBLISHED = 'PUBLISHED';
    public const UNPUBLISHED = 'UNPUBLISHED';
    public const NOT_GENERATED = 'NOT_GENERATED';

    /**
     * @param list<StatisticsMemberRow> $members
     * @param list<int>                 $periodNumbers
     */
    public function __construct(
        public string $groupStableId,
        public string $groupLabel,
        public array $members,
        public ?string $publication = null,
        public array $periodNumbers = [],
    ) {
    }
}
