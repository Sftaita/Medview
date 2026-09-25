<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\PlanningLine;
use App\Entity\PlanningPeriodStatus;

/** What the pipeline needs to run for one line, as observed right now. */
final readonly class LaunchLineReadiness
{
    /**
     * @param array<string, int> $familyUnitCounts REQUIRED DutyUnit count per
     *                                             AllocationFamily name (docs/decisions.md D137)
     *                                             — keyed by the family's own display name,
     *                                             never a hardcoded label; a unit whose pattern
     *                                             carries no family is counted under the empty
     *                                             string key, which the API layer renders as
     *                                             "Sans famille" — counted once per unit, never
     *                                             once per constituent Duty (D136 Scenario F).
     */
    public function __construct(
        public PlanningLine $line,
        public int $memberCount,
        public int $dutyCount,
        public PlanningPeriodStatus $periodStatus,
        public bool $hasActiveRuleSet,
        public array $familyUnitCounts = [],
    ) {
    }
}
