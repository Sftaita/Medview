<?php

declare(strict_types=1);

namespace App\Service;

use App\Demand\LiveCoverageState;
use App\Demand\UnitDemand;
use App\Entity\Duty;
use App\Entity\PlanningLine;
use App\Entity\PlanningTeamMember;

/**
 * What a change on a source line did to one conditional block depending on
 * it (docs/decisions.md D165) — its live state before and after, computed
 * by the backend from the LiveDemandView so the frontend never re-runs the
 * conditional logic. Nothing was written on the conditional line: this only
 * reports.
 */
final readonly class DependentImpact
{
    /**
     * @param list<Duty> $block the whole conditional block, in local-date order
     */
    public function __construct(
        public PlanningLine $line,
        public array $block,
        public LiveCoverageState $previousState,
        public LiveCoverageState $newState,
        public UnitDemand $demand,
        public ?PlanningTeamMember $assignee,
    ) {
    }

    public function changed(): bool
    {
        return $this->previousState !== $this->newState;
    }
}
