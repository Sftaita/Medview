<?php

declare(strict_types=1);

namespace App\Demand;

use App\Entity\Duty;

/**
 * Whether one duty is needed, and why (docs/decisions.md D163) — what every
 * consumer reads instead of re-deriving it.
 *
 * - `required`: must be covered. For a conditional duty it always equals
 *   its block's answer (a block is atomic).
 * - `reason`: DemandReason — for a conditional duty, TRIGGERED /
 *   TRIGGERED_BY_BLOCK when required, otherwise the reason its own day is
 *   not triggered.
 * - `ownDay`: the evaluation of this duty's own day (coverage source,
 *   holder, trigger) — null for an intrinsic duty.
 * - `triggeringDuties`: the days of its block that triggered the
 *   requirement (itself included when triggered) — empty when not required
 *   or intrinsic.
 * - `determined`: its unit's answer is known (UnitDemand::$determined) —
 *   false means "unknown", never "not required".
 */
final readonly class DutyDemand
{
    /**
     * @param list<Duty> $triggeringDuties
     */
    public function __construct(
        public Duty $duty,
        public bool $required,
        public DemandReason $reason,
        public ?DayDemand $ownDay,
        public array $triggeringDuties,
        public bool $determined = true,
    ) {
    }

    public function isConditional(): bool
    {
        return null !== $this->ownDay;
    }
}
