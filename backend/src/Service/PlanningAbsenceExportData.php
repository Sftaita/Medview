<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Everything the absence PDF shows (docs/availability.md §11), computed
 * from the current data at download time — never from a generation
 * snapshot. The renderer reads only this, never the database.
 */
final readonly class PlanningAbsenceExportData
{
    /**
     * @param string                            $first   first day of the planning ("Y-m-d", inclusive)
     * @param string                            $last    last day of the planning ("Y-m-d", inclusive — Planning::endsAt minus one day)
     * @param list<PlanningAbsenceExportMember> $members sorted by last name, then first name
     */
    public function __construct(
        public string $planningName,
        public string $first,
        public string $last,
        public \DateTimeImmutable $generatedAt,
        public array $members,
    ) {
    }
}
