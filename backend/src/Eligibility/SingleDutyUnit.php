<?php

declare(strict_types=1);

namespace App\Eligibility;

use App\Entity\Duty;

/**
 * A DutyUnit wrapping exactly one standalone Duty (no DutyGroupInstance).
 */
final readonly class SingleDutyUnit implements DutyUnit
{
    public function __construct(private Duty $duty)
    {
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
        return $this->duty->isRequired();
    }
}
