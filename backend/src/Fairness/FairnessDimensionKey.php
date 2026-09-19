<?php

declare(strict_types=1);

namespace App\Fairness;

/**
 * A single fairness dimension identity — the typed replacement for a bare
 * string/array key (docs/fairness.md §Dimensions). `DUTY_TYPE` is always
 * paired with a `$dutyTypeStableId` (never a runtime auto-increment id,
 * consistent with every other stable-identity rule in this domain); every
 * other type never carries one — the constructor makes the invalid
 * combination unrepresentable rather than merely undocumented.
 */
final readonly class FairnessDimensionKey
{
    private function __construct(
        public FairnessDimensionType $type,
        public ?string $dutyTypeStableId = null,
    ) {
        if (FairnessDimensionType::DUTY_TYPE === $type && null === $dutyTypeStableId) {
            throw new \InvalidArgumentException('A DUTY_TYPE dimension key requires a dutyTypeStableId.');
        }

        if (FairnessDimensionType::DUTY_TYPE !== $type && null !== $dutyTypeStableId) {
            throw new \InvalidArgumentException(sprintf('Only a DUTY_TYPE dimension key may carry a dutyTypeStableId, not %s.', $type->value));
        }
    }

    public static function totalDuties(): self
    {
        return new self(FairnessDimensionType::TOTAL_DUTIES);
    }

    public static function weightedWorkload(): self
    {
        return new self(FairnessDimensionType::WEIGHTED_WORKLOAD);
    }

    public static function friday(): self
    {
        return new self(FairnessDimensionType::FRIDAY);
    }

    public static function saturday(): self
    {
        return new self(FairnessDimensionType::SATURDAY);
    }

    public static function sunday(): self
    {
        return new self(FairnessDimensionType::SUNDAY);
    }

    public static function dutyType(string $dutyTypeStableId): self
    {
        return new self(FairnessDimensionType::DUTY_TYPE, $dutyTypeStableId);
    }

    /**
     * The stable string form used as an array key wherever a
     * FairnessDimensionKey itself cannot be one (PHP arrays only accept
     * scalar keys) — see FairnessDimensionValues.
     */
    public function toStringKey(): string
    {
        return null !== $this->dutyTypeStableId
            ? sprintf('%s:%s', $this->type->value, $this->dutyTypeStableId)
            : $this->type->value;
    }

    public function equals(self $other): bool
    {
        return $this->type === $other->type && $this->dutyTypeStableId === $other->dutyTypeStableId;
    }
}
