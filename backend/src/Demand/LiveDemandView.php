<?php

declare(strict_types=1);

namespace App\Demand;

use App\Eligibility\DutyUnit;
use App\Entity\Duty;

/**
 * The LIVE DemandView (docs/decisions.md D163): the demand of the current
 * calendar, as loaded by LiveDemandViewFactory at one instant — rules of
 * each line, and who holds each source duty per DutyAssignment.current.
 * Build a new one to see a later change: it never refreshes itself, so one
 * screen or one request reads one consistent state.
 */
final class LiveDemandView implements DemandView
{
    /**
     * @param array<int, DemandRules>   $rulesByPeriodId     each line's rules, keyed by its PlanningPeriod id
     * @param array<int, SourceHolding> $holdingBySourceDuty keyed by source duty id — a missing key means its line was never generated
     */
    public function __construct(
        private readonly DemandCalculator $calculator,
        private readonly array $rulesByPeriodId,
        private readonly array $holdingBySourceDuty,
    ) {
    }

    public function forDuty(Duty $duty): DutyDemand
    {
        return $this->unitOf($duty)->forDuty($duty);
    }

    public function forUnit(DutyUnit $unit): UnitDemand
    {
        return $this->compute($unit->getDuties());
    }

    private function unitOf(Duty $duty): UnitDemand
    {
        $group = $duty->getGroupInstance();

        return $this->compute(null !== $group ? array_values($group->getDuties()->toArray()) : [$duty]);
    }

    /**
     * @param list<Duty> $duties
     */
    private function compute(array $duties): UnitDemand
    {
        $rules = $this->rulesByPeriodId[(int) $duties[0]->getPlanningPeriod()->getId()] ?? DemandRules::independent();

        return $this->calculator->unit(
            $duties,
            $rules,
            fn (Duty $source): SourceHolding => $this->holdingBySourceDuty[(int) $source->getId()] ?? SourceHolding::notGenerated(),
        );
    }
}
