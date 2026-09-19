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
 */
final class RequiredDemandBuilder
{
    public function __construct(
        private readonly DutyRepository $dutyRepository,
        private readonly DimensionMembershipCalculator $dimensionMembershipCalculator,
    ) {
    }

    public function build(PlanningPeriod $planningPeriod): FairnessDimensionValues
    {
        $total = FairnessDimensionValues::empty();

        foreach ($this->dutyRepository->findByPlanningPeriod($planningPeriod) as $duty) {
            if (!$duty->isRequired()) {
                continue;
            }

            $total = $total->plus($this->dimensionMembershipCalculator->forDuty($duty));
        }

        return $total;
    }
}
