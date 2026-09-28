<?php

declare(strict_types=1);

namespace App\Demand;

use App\Entity\Duty;

/**
 * The evaluation of ONE conditional duty's own day (docs/decisions.md
 * D163): its coverage source, the person who held it when evaluated, the
 * trigger that matched (if any) and the reason. Block propagation is
 * UnitDemand's business, never this one's.
 */
final readonly class DayDemand
{
    public function __construct(
        public Duty $duty,
        public Duty $sourceDuty,
        public Weekday $weekday,
        public ?string $sourceHolderUserStableId,
        public ?DemandTriggerRule $trigger,
        public DemandReason $reason,
    ) {
    }

    public function isTriggered(): bool
    {
        return DemandReason::TRIGGERED === $this->reason;
    }
}
