<?php

declare(strict_types=1);

namespace App\Demand;

/**
 * A line's demand policy as plain values (docs/decisions.md D162) — what
 * DemandTriggerEvaluator reads. No entity, no repository: the same rules
 * can come from the live PlanningLineDemandPolicy or, later, from a copy
 * frozen in a snapshot.
 */
final readonly class DemandRules
{
    /** @var array<string, DemandTriggerRule> keyed by userStableId */
    public array $triggersByUser;

    /**
     * @param list<DemandTriggerRule> $triggers at most one per person
     */
    public function __construct(
        public DemandMode $mode,
        public ?string $sourceLineStableId,
        array $triggers,
    ) {
        if ($mode->isConditional() !== (null !== $sourceLineStableId)) {
            throw new \InvalidArgumentException('A conditional demand has exactly one source line; an independent one has none.');
        }
        if (!$mode->isConditional() && [] !== $triggers) {
            throw new \InvalidArgumentException('An independent demand has no trigger.');
        }

        $byUser = [];
        foreach ($triggers as $trigger) {
            if (isset($byUser[$trigger->userStableId])) {
                throw new \InvalidArgumentException(\sprintf('Two triggers for the same person "%s".', $trigger->userStableId));
            }
            $byUser[$trigger->userStableId] = $trigger;
        }
        $this->triggersByUser = $byUser;
    }

    /** No policy at all means exactly this (D162). */
    public static function independent(): self
    {
        return new self(DemandMode::INDEPENDENT, null, []);
    }
}
