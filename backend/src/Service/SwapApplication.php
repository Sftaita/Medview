<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Duty;
use App\Entity\DutyAssignment;
use App\Entity\PlanningTeamMember;

/**
 * What DutyReassignmentService::applySwap() wrote (docs/decisions.md D178):
 * both whole units, who now holds each, and the exact DutyAssignment rows
 * superseded and created — recorded in the SWAP_COMPLETED event.
 */
final readonly class SwapApplication
{
    /**
     * @param list<Duty>           $offeredBlock     the requester's unit, now held by $counterpart
     * @param list<Duty>           $counterpartBlock the counterpart's unit, now held by $requester
     * @param list<DutyAssignment> $superseded
     * @param list<DutyAssignment> $created
     */
    public function __construct(
        public array $offeredBlock,
        public array $counterpartBlock,
        public PlanningTeamMember $requester,
        public PlanningTeamMember $counterpart,
        public array $superseded,
        public array $created,
    ) {
    }
}
