<?php

declare(strict_types=1);

namespace App\Service;

use App\Demand\DutyDemand;
use App\Demand\LiveCoverageState;
use App\Demand\UnitDemand;
use App\Entity\Duty;
use App\Entity\PlanningLine;
use App\Entity\PlanningTeamMember;

/**
 * One conditional unit the publication preflight reports on its live state
 * (docs/decisions.md D165/D166), once per unit (a block once, with all its
 * days):
 * - `UNDETERMINED_CONDITIONAL_DEMAND` — its source duty has no holder: blocks
 *   publication and republication, never read as "not required";
 * - `SUPERFLUOUS_CONDITIONAL_COVERAGE` — not required any more but still held:
 *   a warning, never a blocker, never removed automatically.
 */
final readonly class ConditionalPublicationDuty
{
    public const SUPERFLUOUS = 'SUPERFLUOUS_CONDITIONAL_COVERAGE';
    public const UNDETERMINED = 'UNDETERMINED_CONDITIONAL_DEMAND';

    /**
     * @param list<Duty> $block      the whole unit, in local-date order
     * @param DutyDemand $dutyDemand the demand of $duty (the unit's first day), with its own day's explanation
     */
    public function __construct(
        public string $code,
        public PlanningLine $line,
        public Duty $duty,
        public array $block,
        public ?PlanningTeamMember $holder,
        public UnitDemand $unitDemand,
        public DutyDemand $dutyDemand,
        public LiveCoverageState $state,
    ) {
    }
}
