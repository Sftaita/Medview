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
     * @param string                            $first      first day exported ("Y-m-d", inclusive) — the planning's by default
     * @param string                            $last       last day exported ("Y-m-d", inclusive — by default Planning::endsAt minus one day)
     * @param list<PlanningAbsenceExportMember> $members    sorted by last name, then first name
     * @param ?string                           $scopeLabel "Période 2", "Périodes 1 à 2" — on a planning with several periods only (docs/decisions.md D200)
     */
    public function __construct(
        public string $planningName,
        public string $first,
        public string $last,
        public \DateTimeImmutable $generatedAt,
        public array $members,
        public ?string $scopeLabel = null,
    ) {
    }
}
