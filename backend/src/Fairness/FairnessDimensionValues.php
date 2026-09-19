<?php

declare(strict_types=1);

namespace App\Fairness;

/**
 * An immutable `FairnessDimensionKey → float` map — the single reusable
 * typed container behind every per-dimension quantity in this lot
 * (dimensionMembership of one Duty/DutyUnit, requiredDemand,
 * effectiveExposure, grossTargets, discretionaryTargets,
 * structurallyForcedLoad — docs/fairness.md), instead of five separate
 * ad-hoc associative arrays. Values are never rounded here (deviation math
 * always works on exact fractions, docs/allocation-algorithm.md §5) —
 * rounding, if ever needed, belongs strictly to display code.
 */
final readonly class FairnessDimensionValues
{
    /**
     * @param array<string, float> $values keyed by FairnessDimensionKey::toStringKey()
     */
    private function __construct(private array $values)
    {
    }

    public static function empty(): self
    {
        return new self([]);
    }

    /**
     * @param array<string, float> $values
     */
    public static function fromKeyedArray(array $values): self
    {
        return new self($values);
    }

    public function get(FairnessDimensionKey $key): float
    {
        return $this->values[$key->toStringKey()] ?? 0.0;
    }

    /**
     * Returns a new instance with $amount added on top of whatever $key
     * already held (0.0 if absent) — never mutates $this.
     */
    public function withAdded(FairnessDimensionKey $key, float $amount): self
    {
        $values = $this->values;
        $stringKey = $key->toStringKey();
        $values[$stringKey] = ($values[$stringKey] ?? 0.0) + $amount;

        return new self($values);
    }

    /**
     * Every value multiplied by $factor — used to apply
     * participationFactor/structuralOpportunity to a Duty's dimension
     * contribution (EffectiveExposureService) without a manual loop at
     * every call site.
     */
    public function scaledBy(float $factor): self
    {
        $values = [];
        foreach ($this->values as $stringKey => $amount) {
            $values[$stringKey] = $amount * $factor;
        }

        return new self($values);
    }

    /**
     * Element-wise sum against another instance — every key present in
     * either operand appears in the result.
     */
    public function plus(self $other): self
    {
        $values = $this->values;
        foreach ($other->values as $stringKey => $amount) {
            $values[$stringKey] = ($values[$stringKey] ?? 0.0) + $amount;
        }

        return new self($values);
    }

    /**
     * @return array<string, float> keyed by FairnessDimensionKey::toStringKey() — for
     *                              assertions/serialization only, never re-parsed back
     *                              into keys elsewhere in the domain
     */
    public function toStringKeyedArray(): array
    {
        return $this->values;
    }
}
