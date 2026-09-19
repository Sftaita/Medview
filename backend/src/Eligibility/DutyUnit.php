<?php

declare(strict_types=1);

namespace App\Eligibility;

use App\Entity\Duty;

/**
 * The unit the future optimization problem actually decides over — a
 * plain Duty, or an atomic DutyGroupInstance (docs/allocation-algorithm.md
 * §9/§14, D052): "éligible en bloc ou inéligible en bloc, jamais une
 * demi-éligibilité". Everything in this lot is written against this
 * abstraction rather than Duty directly, so the future
 * OptimizationProblemBuilder never has to special-case standalone duties
 * vs groups.
 */
interface DutyUnit
{
    /**
     * Stable across regenerations of the same PlanningPeriod (D042) — a
     * Duty's or DutyGroupInstance's own $stableId, never the
     * auto-increment $id.
     */
    public function getStableKey(): string;

    /**
     * @return non-empty-list<Duty>
     */
    public function getDuties(): array;

    public function isGrouped(): bool;

    /**
     * Whether this unit belongs in `requiredDutyUnits` (true) or
     * `optionalDutyUnits` (false) of the future OptimizationProblem
     * (docs/fairness.md, docs/allocation-algorithm.md §21) — a unit is
     * atomic for this classification exactly like it is for eligibility:
     * classified as a whole, never a mix of REQUIRED and OPTIONAL Duties.
     *
     * @throws \LogicException if a DutyGroupUnit's constituent Duties
     *                         disagree on demandType — should never happen
     *                         (Duty's own constructor already guards
     *                         against it), never silently picked one way
     */
    public function isRequired(): bool;
}
