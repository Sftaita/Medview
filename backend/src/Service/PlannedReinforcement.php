<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Duty;
use App\Entity\DutyGroupInstance;

/**
 * One reinforcement unit still to create (docs/decisions.md D224): the
 * triggered source duties of ONE source block (or one standalone source
 * duty) that have no reinforcement yet. Two days or more become one block;
 * one day a standalone duty — unless $joinGroup is set: the source block is
 * already reinforced and these days start triggering later, they join it.
 */
final readonly class PlannedReinforcement
{
    /**
     * @param non-empty-list<Duty> $sourceDuties in date order
     */
    public function __construct(
        public array $sourceDuties,
        public ?DutyGroupInstance $joinGroup = null,
    ) {
    }

    public function joinsExistingBlock(): bool
    {
        return null !== $this->joinGroup;
    }
}
