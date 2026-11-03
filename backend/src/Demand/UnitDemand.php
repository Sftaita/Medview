<?php

declare(strict_types=1);

namespace App\Demand;

use App\Entity\Duty;

/**
 * The demand of a whole unit — a standalone duty, or every duty of one
 * DutyGroupInstance (docs/decisions.md D163). One answer for the unit,
 * shared by all its duties: a conditional block is required as soon as ONE
 * of its days is triggered, and then entirely, for one person.
 *
 * `determined` (docs/decisions.md D164): false when no day is triggered
 * and at least one day could not be evaluated (nobody holds its source
 * duty) — the answer is then "unknown", never "not required". A triggered
 * day always settles the question (the whole block is required).
 *
 * `partial` (docs/decisions.md D224): a block inherited from the source line
 * some of whose days no longer trigger — each day is then required on its
 * own (DutyDemand), and the block is never completed automatically.
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
        public bool $determined = true,
        public bool $partial = false,
    ) {
        if ($required && !$determined) {
            throw new \InvalidArgumentException('A required unit is always determined.');
        }
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
