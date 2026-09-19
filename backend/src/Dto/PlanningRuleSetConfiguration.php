<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Validated shape of PlanningRuleSet::$configuration (docs/decisions.md
 * D036, docs/allocation-algorithm.md §3). Only the handful of rules
 * already concretely specified get a typed, validated property —
 * everything still conceptual/evolving (fairness dimension sets, spacing
 * windows, preference tolerances) lives in $additionalPolicy, which is
 * intentionally unstructured until the lot that stabilizes its shape.
 * Nothing in this lot reads $additionalPolicy — it exists so a RuleSet
 * created now does not need reshaping later.
 *
 * These are all TEAM_MIN_REST-style POLICY_HARD values (D036): a team's
 * own configured policy, never the LEGAL_MIN_REST floor, which this DTO
 * deliberately does not model (its source is the system/jurisdiction, not
 * a per-team RuleSet — see docs/planning-domain.md "Dette / points
 * ouverts").
 */
final class PlanningRuleSetConfiguration
{
    #[Assert\Positive]
    public ?int $maxDutiesPerFairnessPeriod = null;

    #[Assert\Positive]
    public ?int $maxWeekendsPerFairnessPeriod = null;

    /**
     * Historical field, no longer read by the solver (docs/decisions.md
     * D105, Lot 6D.1): `TEAM_MIN_REST`/`LEGAL_MIN_REST` are now
     * *per-generation* options (`App\Entity\RestPolicyOptions`, set when a
     * `PlanningGeneration` is created), never a team-wide default silently
     * applied to every planning — `AssignmentConflictAnalyzer` reads
     * `PlanningGeneration::getRestPolicy()` exclusively. Kept here,
     * unused, only because dropping the column outright was not
     * demonstrated as necessary for this lot.
     */
    #[Assert\Positive]
    public ?int $teamMinRestHours = null;

    #[Assert\Positive]
    public ?int $maxConsecutiveNights = null;

    /**
     * decayFactor ∈ (0,1) for named-holiday history weighting
     * (docs/allocation-algorithm.md §7).
     */
    #[Assert\GreaterThan(0)]
    #[Assert\LessThan(1)]
    public ?float $holidayDecayFactor = null;

    /**
     * Deliberately unstructured — see class docblock.
     *
     * @var array<string, mixed>
     */
    #[Assert\Type('array')]
    public array $additionalPolicy = [];

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $config = new self();
        $config->maxDutiesPerFairnessPeriod = $data['maxDutiesPerFairnessPeriod'] ?? null;
        $config->maxWeekendsPerFairnessPeriod = $data['maxWeekendsPerFairnessPeriod'] ?? null;
        $config->teamMinRestHours = $data['teamMinRestHours'] ?? null;
        $config->maxConsecutiveNights = $data['maxConsecutiveNights'] ?? null;
        $config->holidayDecayFactor = $data['holidayDecayFactor'] ?? null;
        $config->additionalPolicy = $data['additionalPolicy'] ?? [];

        return $config;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'maxDutiesPerFairnessPeriod' => $this->maxDutiesPerFairnessPeriod,
            'maxWeekendsPerFairnessPeriod' => $this->maxWeekendsPerFairnessPeriod,
            'teamMinRestHours' => $this->teamMinRestHours,
            'maxConsecutiveNights' => $this->maxConsecutiveNights,
            'holidayDecayFactor' => $this->holidayDecayFactor,
            'additionalPolicy' => $this->additionalPolicy,
        ];
    }
}
