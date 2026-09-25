<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\PlanningPeriod;
use App\Fairness\FairnessDimensionValues;
use App\Repository\DutyRepository;

/**
 * requiredDemand(dimension) — fixed from `Duty.demandType = REQUIRED`
 * alone (docs/allocation-algorithm.md §5, docs/fairness.md
 * §RequiredDemand). Never depends on candidate count, eligibility, or a
 * future solve — a pure count/sum over the PlanningPeriod's own Duties.
 * `Duty.demandType = OPTIONAL` never contributes.
 *
 * Scoped exclusively to the given PlanningPeriod
 * (`DutyRepository::findByPlanningPeriod`, itself scoped to one
 * PlanningTeam/PlanningLine by construction, docs/planning.md §5) — never
 * aggregates across PlanningLines.
 *
 * Builds `DutyUnit`s (`DutyUnitFactory`) rather than summing `forDuty()`
 * over raw Duty rows (docs/decisions.md D136): every calendar/analytic
 * dimension is still correctly a per-Duty sum either way (`forDutyUnit()`
 * itself sums `forDuty()` over the unit's constituents), but
 * `ALLOCATION_FAMILY` must be counted once per *unit* — building units
 * first and calling `forDutyUnit()` is the only way both stay correct
 * from the same call site (a 3-day REQUIRED block must contribute
 * `ALLOCATION_FAMILY:WEEKEND += 1`, never `+= 3`, docs/decisions.md D136
 * Scenario F).
 */
final class RequiredDemandBuilder
{
    public function __construct(
        private readonly DutyRepository $dutyRepository,
        private readonly DimensionMembershipCalculator $dimensionMembershipCalculator,
        private readonly DutyUnitFactory $dutyUnitFactory,
    ) {
    }

    public function build(PlanningPeriod $planningPeriod): FairnessDimensionValues
    {
        $duties = $this->dutyRepository->findByPlanningPeriod($planningPeriod);
        $dutyUnits = $this->dutyUnitFactory->fromDuties($duties);

        $total = FairnessDimensionValues::empty();

        foreach ($dutyUnits as $unit) {
            if (!$unit->isRequired()) {
                continue;
            }

            $total = $total->plus($this->dimensionMembershipCalculator->forDutyUnit($unit));
        }

        return $total;
    }
}
