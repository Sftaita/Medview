<?php

declare(strict_types=1);

namespace App\Demand;

use App\Eligibility\DutyUnit;
use App\Entity\Duty;

/**
 * The SNAPSHOT DemandView (docs/decisions.md D164): what one generation
 * decided and froze. It reads nothing but its snapshot's frozen decisions
 * (each conditional day's holder, trigger and reason) and frozen triggers,
 * plus the duties themselves (immutable: their coverage source never
 * changes) — never DutyAssignment.current, never the policy in force. A
 * later reassignment of the source line, or a new policy version, can
 * therefore never change what an old generation says about its own demand.
 *
 * The block rule is the same one the LIVE view uses
 * (DemandCalculator::fromDays), applied to the frozen days.
 */
final class SnapshotDemandView implements DemandView
{
    /** @var array<string, DemandTriggerRule> the frozen triggers, by stable id */
    private array $triggers = [];

    /**
     * @param DemandRules|null                 $frozenRules       the snapshot's frozen policy, null for an independent line
     * @param array<int, FrozenDemandDecision> $decisionsByDutyId keyed by conditional duty id
     */
    public function __construct(
        private readonly DemandCalculator $calculator,
        ?DemandRules $frozenRules,
        private readonly array $decisionsByDutyId,
    ) {
        foreach ($frozenRules?->triggersByUser ?? [] as $rule) {
            if (null !== $rule->triggerStableId) {
                $this->triggers[$rule->triggerStableId] = $rule;
            }
        }
    }

    public function forDuty(Duty $duty): DutyDemand
    {
        $group = $duty->getGroupInstance();

        return $this->compute(null !== $group ? array_values($group->getDuties()->toArray()) : [$duty])->forDuty($duty);
    }

    public function forUnit(DutyUnit $unit): UnitDemand
    {
        return $this->compute($unit->getDuties());
    }

    /**
     * @param list<Duty> $duties
     */
    private function compute(array $duties): UnitDemand
    {
        if (!$duties[0]->isConditional()) {
            return $this->calculator->intrinsic($duties);
        }

        usort($duties, static fn (Duty $a, Duty $b): int => [$a->getLocalDate(), (string) $a->getStableId()] <=> [$b->getLocalDate(), (string) $b->getStableId()]);

        return DemandCalculator::fromDays(array_map($this->day(...), $duties));
    }

    private function day(Duty $duty): DayDemand
    {
        $source = $duty->getCoverageSource() ?? throw new \LogicException('A conditional duty always has a coverage source.');
        $decision = $this->decisionsByDutyId[(int) $duty->getId()] ?? null;
        if (null === $decision) {
            return new DayDemand($duty, $source, Weekday::ofDate($duty->getLocalDate()), null, null, DemandReason::NOT_IN_SNAPSHOT);
        }

        return new DayDemand(
            $duty,
            $source,
            $decision->weekday,
            $decision->sourceUserStableId,
            null !== $decision->triggerStableId ? ($this->triggers[$decision->triggerStableId] ?? null) : null,
            $decision->dayReason,
        );
    }
}
