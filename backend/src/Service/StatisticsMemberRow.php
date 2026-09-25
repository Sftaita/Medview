<?php

declare(strict_types=1);

namespace App\Service;

/**
 * One member's duty count, by ISO weekday and by AllocationFamily, within
 * one StatisticsScope (docs/decisions.md D132/D137). Purely descriptive —
 * never a judgment ("balanced"/"unbalanced"): the manager reads the
 * numbers themselves (§45 of the spec).
 */
final readonly class StatisticsMemberRow
{
    /**
     * @param array<string, int> $countsByWeekday keyed 'MON'..'SUN', always all seven keys present
     * @param array<string, int> $countsByFamily   keyed by AllocationFamily name (docs/decisions.md D137);
     *                                              the empty-string key groups duties with no family. Names,
     *                                              never hardcoded — the same set for every row of a group,
     *                                              built from what that group's generation actually used.
     */
    public function __construct(
        public string $teamMemberStableId,
        public string $firstName,
        public string $lastName,
        public array $countsByWeekday,
        public array $countsByFamily,
        public int $total,
    ) {
    }
}
