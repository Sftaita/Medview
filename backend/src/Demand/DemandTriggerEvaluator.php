<?php

declare(strict_types=1);

namespace App\Demand;

/**
 * The one answer to "does this person, holding the source line on this
 * weekday, trigger the conditional line?" (docs/decisions.md D162).
 *
 * Pure: plain values in, the matching trigger (or null) out. It never reads
 * the database, the calendar, DutyAssignment.current or a snapshot — the
 * layers that know *who* holds the source duty (generation, live calendar)
 * call it with that person, and decide themselves what to do with the
 * answer (block propagation, required-or-not, history).
 *
 *   independent line                    → never triggered (no conditional demand)
 *   person without a trigger            → not triggered
 *   person with a trigger, day covered  → triggered, with that trigger
 *   person with a trigger, other day    → not triggered
 *   nobody holds the source duty (null) → not triggered
 */
final class DemandTriggerEvaluator
{
    public function matchingTrigger(DemandRules $rules, ?string $sourceUserStableId, Weekday $weekday): ?DemandTriggerRule
    {
        if (!$rules->mode->isConditional() || null === $sourceUserStableId) {
            return null;
        }

        $trigger = $rules->triggersByUser[$sourceUserStableId] ?? null;

        return null !== $trigger && $trigger->covers($weekday) ? $trigger : null;
    }

    public function isTriggered(DemandRules $rules, ?string $sourceUserStableId, Weekday $weekday): bool
    {
        return null !== $this->matchingTrigger($rules, $sourceUserStableId, $weekday);
    }
}
