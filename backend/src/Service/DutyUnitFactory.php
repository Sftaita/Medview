<?php

declare(strict_types=1);

namespace App\Service;

use App\Demand\DemandView;
use App\Eligibility\DutyGroupUnit;
use App\Eligibility\DutyUnit;
use App\Eligibility\SingleDutyUnit;
use App\Entity\Duty;
use App\Entity\DutyGroupInstance;

/**
 * Groups a flat list of Duty rows into DutyUnits — every standalone Duty
 * becomes a SingleDutyUnit, every DutyGroupInstance's constituent Duty rows
 * fold into one DutyGroupUnit — the exact grouping `EligibilityMatrixBuilder`
 * already needed. Extracted here (docs/decisions.md D136) so
 * `RequiredDemandBuilder` can build the same units instead of iterating raw
 * Duty rows: computing `ALLOCATION_FAMILY` correctly requires counting
 * *units*, not Duties (a 3-day block must never look like 3 units), and
 * duplicating this grouping in a second place would have let the two
 * drift apart.
 */
final class DutyUnitFactory
{
    /**
     * @param iterable<Duty> $duties
     *
     * @return list<DutyUnit> deterministically ordered by stable key —
     *                        never by auto-increment id or insertion order
     *                        (docs/eligibility.md §Déterminisme)
     */
    public function fromDuties(iterable $duties, ?DemandView $demand = null): array
    {
        $dutyUnits = [];

        /** @var array<int, list<Duty>> $groupedDuties */
        $groupedDuties = [];
        /** @var array<int, DutyGroupInstance> $groupInstances */
        $groupInstances = [];

        foreach ($duties as $duty) {
            $group = $duty->getGroupInstance();

            if (null === $group) {
                $dutyUnits[] = new SingleDutyUnit($duty);
                continue;
            }

            $groupInstances[$group->getId()] = $group;
            $groupedDuties[$group->getId()][] = $duty;
        }

        foreach ($groupedDuties as $groupId => $dutiesInGroup) {
            $dutyUnits[] = new DutyGroupUnit($groupInstances[$groupId], $dutiesInGroup);
        }

        if (null !== $demand) {
            $dutyUnits = $this->applyDemand($dutyUnits, $demand);
        }

        usort($dutyUnits, static fn (DutyUnit $a, DutyUnit $b): int => $a->getStableKey() <=> $b->getStableKey());

        return $dutyUnits;
    }

    /**
     * docs/decisions.md D164 — the units of a generation's problem: an
     * intrinsic unit is kept as it is; a conditional unit is kept only when
     * the demand view finds it REQUIRED (then flagged so), and is otherwise
     * ABSENT — never turned into an optional unit, never given to the
     * solver. A unit whose demand could not be determined is absent too.
     *
     * @param list<DutyUnit> $units
     *
     * @return list<DutyUnit>
     */
    private function applyDemand(array $units, DemandView $demand): array
    {
        $kept = [];
        foreach ($units as $unit) {
            if (!$unit->getDuties()[0]->isConditional()) {
                $kept[] = $unit;
                continue;
            }
            if (!$demand->forUnit($unit)->required) {
                continue;
            }
            $kept[] = $unit instanceof DutyGroupUnit
                ? new DutyGroupUnit($unit->getGroupInstance(), $unit->getDuties(), true)
                : new SingleDutyUnit($unit->getDuties()[0], true);
        }

        return $kept;
    }
}
