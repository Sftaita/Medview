<?php

declare(strict_types=1);

namespace App\Eligibility;

use App\Entity\Duty;
use App\Entity\DutyGroupInstance;

/**
 * A DutyUnit wrapping an atomic DutyGroupInstance and its constituent
 * Duty rows — evaluated as a single block (docs/eligibility.md §Groupes).
 */
final readonly class DutyGroupUnit implements DutyUnit
{
    /**
     * @param non-empty-list<Duty> $duties
     */
    public function __construct(
        private DutyGroupInstance $groupInstance,
        private array $duties,
        private ?bool $demandRequired = null,
    ) {
        if (null !== $demandRequired && !$duties[0]->isConditional()) {
            throw new \InvalidArgumentException('Only a conditional unit carries a demand decision.');
        }
    }

    public function getGroupInstance(): DutyGroupInstance
    {
        return $this->groupInstance;
    }

    public function getStableKey(): string
    {
        return (string) $this->groupInstance->getStableId();
    }

    public function getDuties(): array
    {
        return $this->duties;
    }

    public function isGrouped(): bool
    {
        return true;
    }

    /**
     * @throws \LogicException if the group's constituent Duties disagree on
     *                         demandType — Duty's own constructor already
     *                         makes this unreachable in practice (see its
     *                         docblock, docs/decisions.md D083);
     *                         this is the "never mask it" fallback, not a
     *                         silent pick.
     */
    public function isRequired(): bool
    {
        // A conditional block is atomic: one decision for the whole unit (docs/decisions.md D163/D164).
        if ($this->duties[0]->isConditional()) {
            return $this->demandRequired ?? throw new \LogicException('A conditional unit built without its demand decision has no answer: build it through a DemandView (docs/decisions.md D164).');
        }

        $first = $this->duties[0]->isRequired();

        foreach ($this->duties as $duty) {
            if ($duty->isRequired() !== $first) {
                throw new \LogicException(sprintf('DutyGroupInstance %s mixes REQUIRED and OPTIONAL Duties — cannot be classified as a single requiredDutyUnit/optionalDutyUnit.', $this->groupInstance->getStableId()));
            }
        }

        return $first;
    }
}
