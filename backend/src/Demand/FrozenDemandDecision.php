<?php

declare(strict_types=1);

namespace App\Demand;

/**
 * One frozen demand decision as plain values (docs/decisions.md D164) —
 * what SnapshotDemandView reads, mapped from a
 * PlanningSnapshotDemandDecision row.
 */
final readonly class FrozenDemandDecision
{
    public function __construct(
        public Weekday $weekday,
        public ?string $sourceUserStableId,
        public ?string $triggerStableId,
        public DemandReason $dayReason,
    ) {
    }
}
