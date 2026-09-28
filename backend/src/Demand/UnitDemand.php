<?php

declare(strict_types=1);

namespace App\Demand;

use App\Entity\Duty;

/**
 * The demand of a whole unit — a standalone duty, or every duty of one
 * DutyGroupInstance (docs/decisions.md D163). One answer for the unit,
 * shared by all its duties: a conditional block is required as soon as ONE
 * of its days is triggered, and then entirely, for one person.
 */
final readonly class UnitDemand
{
    /**
     * @param list<DutyDemand> $duties           one per duty of the unit, in local-date order
     * @param list<Duty>       $triggeringDuties the days that triggered the requirement
     */
    public function __construct(
        public bool $required,
        public array $duties,
        public array $triggeringDuties,
    ) {
    }

    public function forDuty(Duty $duty): DutyDemand
    {
        foreach ($this->duties as $demand) {
            if ($demand->duty === $duty) {
                return $demand;
            }
        }

        throw new \InvalidArgumentException('This duty is not part of the unit.');
    }
}
