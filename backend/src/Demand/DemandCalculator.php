<?php

declare(strict_types=1);

namespace App\Demand;

use App\Entity\Duty;
use App\Entity\DutyDemandType;

/**
 * THE demand rule (docs/decisions.md D163), in one place, on plain inputs:
 *
 *   intrinsic duty    → its own demandType (REQUIRED / OPTIONAL, unchanged)
 *   conditional duty  → coverage source → its holder → DemandTriggerEvaluator
 *                       on the duty's own weekday
 *   conditional block → required as soon as ONE day is triggered, then
 *                       entirely (atomic), explaining which day(s) did it
 *
 * It never reads the database, the calendar or a snapshot: the view that
 * calls it supplies the line's rules and who holds each source duty
 * (LIVE: DutyAssignment.current — SNAPSHOT: a generation's frozen
 * decisions, next lot). Every consumer (calendar, reassignment,
 * completion, publication, statistics, exports) goes through a DemandView
 * built on it, never re-deriving "CONDITIONAL + coverageSource + holder +
 * trigger + weekday + block" itself.
 *
 * A conditional duty only ever exists on a day its line's weekly structure
 * allows (an excluded day is never materialized, D163), so a trigger on an
 * excluded weekday can never create a demand here.
 */
final class DemandCalculator
{
    public function __construct(private readonly DemandTriggerEvaluator $evaluator = new DemandTriggerEvaluator())
    {
    }

    /**
     * @param list<Duty>                    $unitDuties the duties of ONE unit (a standalone duty, or a whole block)
     * @param DemandRules                   $rules      the rules of the line the unit belongs to
     * @param callable(Duty): SourceHolding $holdingOf  who holds a given source duty
     */
    public function unit(array $unitDuties, DemandRules $rules, callable $holdingOf): UnitDemand
    {
        if ([] === $unitDuties) {
            throw new \InvalidArgumentException('A unit has at least one duty.');
        }
        usort($unitDuties, static fn (Duty $a, Duty $b): int => [$a->getLocalDate(), (string) $a->getStableId()] <=> [$b->getLocalDate(), (string) $b->getStableId()]);

        $conditional = $unitDuties[0]->isConditional();
        foreach ($unitDuties as $duty) {
            if ($duty->isConditional() !== $conditional) {
                throw new \LogicException('A unit never mixes conditional and intrinsic duties.');
            }
        }

        if (!$conditional) {
            return $this->intrinsic($unitDuties);
        }

        $days = array_map(fn (Duty $duty): DayDemand => $this->day($duty, $rules, $holdingOf), $unitDuties);
        $triggering = array_values(array_map(static fn (DayDemand $d): Duty => $d->duty, array_filter($days, static fn (DayDemand $d): bool => $d->isTriggered())));
        $required = [] !== $triggering;

        $duties = array_map(static fn (DayDemand $day): DutyDemand => new DutyDemand(
            $day->duty,
            $required,
            match (true) {
                $day->isTriggered() => DemandReason::TRIGGERED,
                $required => DemandReason::TRIGGERED_BY_BLOCK,
                default => $day->reason,
            },
            $day,
            $required ? $triggering : [],
        ), $days);

        return new UnitDemand($required, $duties, $triggering);
    }

    /**
     * @param list<Duty> $unitDuties
     */
    private function intrinsic(array $unitDuties): UnitDemand
    {
        $duties = array_map(static fn (Duty $duty): DutyDemand => new DutyDemand(
            $duty,
            DutyDemandType::REQUIRED === $duty->getDemandType(),
            DutyDemandType::REQUIRED === $duty->getDemandType() ? DemandReason::INTRINSIC_REQUIRED : DemandReason::INTRINSIC_OPTIONAL,
            null,
            [],
        ), $unitDuties);

        return new UnitDemand($duties[0]->required, $duties, []);
    }

    /**
     * @param callable(Duty): SourceHolding $holdingOf
     */
    private function day(Duty $duty, DemandRules $rules, callable $holdingOf): DayDemand
    {
        $source = $duty->getCoverageSource() ?? throw new \LogicException('A conditional duty always has a coverage source.');
        $weekday = Weekday::ofDate($duty->getLocalDate());

        if (!$rules->mode->isConditional()) {
            return new DayDemand($duty, $source, $weekday, null, null, DemandReason::LINE_NOT_CONDITIONAL);
        }

        $holding = $holdingOf($source);
        if (!$holding->sourceLineGenerated) {
            return new DayDemand($duty, $source, $weekday, null, null, DemandReason::SOURCE_LINE_NOT_GENERATED);
        }
        if (null === $holding->holderUserStableId) {
            return new DayDemand($duty, $source, $weekday, null, null, DemandReason::SOURCE_UNASSIGNED);
        }

        $trigger = $this->evaluator->matchingTrigger($rules, $holding->holderUserStableId, $weekday);
        if (null !== $trigger) {
            return new DayDemand($duty, $source, $weekday, $holding->holderUserStableId, $trigger, DemandReason::TRIGGERED);
        }

        $reason = isset($rules->triggersByUser[$holding->holderUserStableId]) ? DemandReason::WEEKDAY_NOT_TRIGGERED : DemandReason::HOLDER_HAS_NO_TRIGGER;

        return new DayDemand($duty, $source, $weekday, $holding->holderUserStableId, $rules->triggersByUser[$holding->holderUserStableId] ?? null, $reason);
    }
}
