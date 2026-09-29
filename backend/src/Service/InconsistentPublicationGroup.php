<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Duty;
use App\Entity\DutyGroupInstance;
use App\Entity\PlanningTeamMember;

/**
 * An atomic block whose constituent Duties do not currently share the same
 * assignee (docs/decisions.md D133 §4) — should never happen given the
 * atomicity Sub-lot A enforces, but the publication preflight is an
 * independent defense, not a re-implementation of that guarantee.
 */
final readonly class InconsistentPublicationGroup
{
    /**
     * @param list<Duty>               $block   the block's days, in date order — where to look in the calendar
     * @param list<PlanningTeamMember> $holders the different people currently holding its days
     */
    public function __construct(
        public DutyGroupInstance $group,
        public array $block = [],
        public array $holders = [],
    ) {
    }
}
