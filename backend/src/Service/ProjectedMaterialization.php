<?php

declare(strict_types=1);

namespace App\Service;

/**
 * What the weekly calendar would materialize in one period
 * (WeeklyDutyCalendarService::project(), docs/decisions.md D224) — counted
 * in memory, nothing written.
 */
final readonly class ProjectedMaterialization
{
    /**
     * @param array<string, int>          $familyUnitCounts REQUIRED units per family name (D137), '' without family
     * @param list<CoverageSourceAnomaly> $anomalies
     */
    public function __construct(
        public int $dutyCount,
        public array $familyUnitCounts,
        public array $anomalies,
    ) {
    }
}
