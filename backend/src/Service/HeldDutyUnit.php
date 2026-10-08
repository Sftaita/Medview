<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Duty;
use App\Entity\DutyAssignment;
use App\Entity\PlanningLine;
use App\Entity\PlanningTeamMember;

/**
 * A duty unit (a block once) as somebody holds it right now in the current
 * calendar of a PUBLISHED line (docs/duty-swaps.md §6): its representative
 * duty (the block's first day), the exact current DutyAssignment row of
 * that duty — what a swap request or proposal freezes — and its holder.
 */
final readonly class HeldDutyUnit
{
    /**
     * @param list<Duty> $block in local-date order
     */
    public function __construct(
        public PlanningLine $line,
        public PlanningTeamMember $holder,
        public Duty $representative,
        public DutyAssignment $assignment,
        public array $block,
    ) {
    }

    public function startsAt(): \DateTimeImmutable
    {
        return min(array_map(static fn (Duty $duty): \DateTimeImmutable => $duty->getStartsAt(), $this->block));
    }
}
