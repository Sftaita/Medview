<?php

declare(strict_types=1);

namespace App\Service;

use App\Eligibility\DutyUnit;
use App\Entity\Duty;
use App\Fairness\FairnessDimensionKey;
use App\Fairness\FairnessDimensionValues;

/**
 * Transforms one Duty into its analytical dimension contributions
 * (docs/fairness.md §DimensionMembership) — e.g. a Friday URGENCE duty
 * with workloadValue 1.5 contributes TOTAL_DUTIES=1, WEIGHTED_WORKLOAD=1.5,
 * FRIDAY=1, DUTY_TYPE:URGENCE=1. For a DutyGroupUnit, aggregates its
 * constituent Duties: the group is atomic for *assignment*
 * (docs/allocation-algorithm.md §9), but each Duty inside it stays a
 * distinct analytical unit — a Friday+Saturday+Sunday group contributes
 * TOTAL_DUTIES=3, FRIDAY=1, SATURDAY=1, SUNDAY=1, never TOTAL_DUTIES=1.
 *
 * ALLOCATION_FAMILY (docs/decisions.md D136) is the one dimension that
 * deliberately breaks this "per constituent Duty" rule: it counts *units*,
 * never Duties — a Friday+Saturday+Sunday block assigned to the same
 * person credits `ALLOCATION_FAMILY:WEEKEND += 1`, never `+= 3` (Scenario F
 * of D136's audit). It is therefore computed once in `forDutyUnit()`
 * itself, never inside `forDuty()` (which stays a pure per-Duty
 * calculation reused unchanged by every calendar/analytic dimension above)
 * — every constituent Duty of one DutyGroupInstance shares the exact same
 * $pattern (DutyMaterializationService::materializeGroup() only ever
 * builds Duty rows from one pattern's own components), so reading the
 * family off the unit's first Duty is never a guess between disagreeing
 * values.
 */
final class DimensionMembershipCalculator
{
    public function forDuty(Duty $duty): FairnessDimensionValues
    {
        $values = FairnessDimensionValues::empty()
            ->withAdded(FairnessDimensionKey::totalDuties(), 1.0)
            ->withAdded(FairnessDimensionKey::weightedWorkload(), $duty->getDutyType()->getWorkloadValue())
            ->withAdded(FairnessDimensionKey::dutyType((string) $duty->getDutyType()->getStableId()), 1.0);

        $dayOfWeek = $duty->getLocalDate()->format('N'); // ISO-8601: 1 = Monday, 7 = Sunday

        return match ($dayOfWeek) {
            '5' => $values->withAdded(FairnessDimensionKey::friday(), 1.0),
            '6' => $values->withAdded(FairnessDimensionKey::saturday(), 1.0),
            '7' => $values->withAdded(FairnessDimensionKey::sunday(), 1.0),
            default => $values,
        };
    }

    public function forDutyUnit(DutyUnit $dutyUnit): FairnessDimensionValues
    {
        $total = FairnessDimensionValues::empty();
        foreach ($dutyUnit->getDuties() as $duty) {
            $total = $total->plus($this->forDuty($duty));
        }

        $family = $dutyUnit->getDuties()[0]->getAllocationFamily();
        if (null !== $family) {
            $total = $total->withAdded(FairnessDimensionKey::allocationFamily((string) $family->getStableId()), 1.0);
        }

        return $total;
    }
}
