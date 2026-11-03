<?php

declare(strict_types=1);

namespace App\Service;

/**
 * What a reinforcement line that inherits its blocks needs in one period
 * (docs/decisions.md D224), read without writing anything.
 */
final readonly class ReinforcementPlan
{
    /**
     * @param bool                       $inherits       the line inherits its blocks and its source has a period here
     * @param bool                       $sourceDecided  the source has a calendar (generated, or planned in this launch)
     * @param list<PlannedReinforcement> $units          the reinforcements still to create
     * @param int                        $triggeredCount source duties that trigger a reinforcement now
     * @param int                        $existingCount  reinforcement duties that already exist
     */
    public function __construct(
        public bool $inherits,
        public bool $sourceDecided,
        public array $units,
        public int $triggeredCount,
        public int $existingCount,
    ) {
    }

    public function newDutyCount(): int
    {
        return array_sum(array_map(static fn (PlannedReinforcement $u): int => \count($u->sourceDuties), $this->units));
    }

    /** Units that become blocks (two days or more, not joining an existing block). */
    public function newBlockCount(): int
    {
        return \count(array_filter($this->units, static fn (PlannedReinforcement $u): bool => !$u->joinsExistingBlock() && \count($u->sourceDuties) >= 2));
    }
}
