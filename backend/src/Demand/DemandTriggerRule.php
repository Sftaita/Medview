<?php

declare(strict_types=1);

namespace App\Demand;

/**
 * One trigger as plain values (docs/decisions.md D162): "when this PERSON
 * holds the source line on one of these weekdays, the target line needs
 * `increment` more coverage". Built from a live DemandTrigger today, from a
 * frozen snapshot copy in a later lot — the evaluator never knows which.
 *
 * `increment` is kept explicit (never a bare "doubled" boolean) so that a
 * future "+N" level stays a data change, not a model change; V1 only ever
 * accepts 1.
 */
final readonly class DemandTriggerRule
{
    /** @var list<Weekday> */
    public array $weekdays;

    /**
     * @param iterable<Weekday> $weekdays
     */
    public function __construct(
        public string $userStableId,
        iterable $weekdays,
        public int $increment,
        public ?string $triggerStableId = null,
    ) {
        $this->weekdays = Weekday::sorted($weekdays);
        if ([] === $this->weekdays) {
            throw new \InvalidArgumentException('A demand trigger needs at least one weekday.');
        }
        if ($increment < 1) {
            throw new \InvalidArgumentException('A demand trigger increment must be at least 1.');
        }
    }

    public function covers(Weekday $weekday): bool
    {
        return \in_array($weekday, $this->weekdays, true);
    }
}
