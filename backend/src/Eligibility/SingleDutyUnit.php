<?php

declare(strict_types=1);

namespace App\Eligibility;

use App\Entity\Duty;

/**
 * A DutyUnit wrapping exactly one standalone Duty (no DutyGroupInstance).
 */
final readonly class SingleDutyUnit implements DutyUnit
{
    /**
     * @param bool|null $demandRequired only for a CONDITIONAL duty: whether the demand view that built this unit
     *                                  found it required (docs/decisions.md D164) — never set for an intrinsic duty
     */
    public function __construct(private Duty $duty, private ?bool $demandRequired = null)
    {
        if (null !== $demandRequired && !$duty->isConditional()) {
            throw new \InvalidArgumentException('Only a conditional unit carries a demand decision.');
        }
    }

    public function getDuty(): Duty
    {
        return $this->duty;
    }

    public function getStableKey(): string
    {
        return (string) $this->duty->getStableId();
    }

    public function getDuties(): array
    {
        return [$this->duty];
    }

    public function isGrouped(): bool
    {
        return false;
    }

    public function isRequired(): bool
    {
        if ($this->duty->isConditional()) {
            return $this->demandRequired ?? throw new \LogicException('A conditional unit built without its demand decision has no answer: build it through a DemandView (docs/decisions.md D164).');
        }

        return $this->duty->isRequired();
    }
}
