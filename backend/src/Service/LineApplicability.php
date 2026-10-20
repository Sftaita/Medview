<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Whether a line takes part in a period's generation, publication and
 * completeness, and why (docs/decisions.md D194, lot L4a) — always derived
 * (LineApplicabilityService), never stored:
 *
 * - `INACTIVE`: the line is inactive — out of everything, as before;
 * - `NOT_IN_PERIOD`: the line has no period in this cycle (§5.5);
 * - `NO_DEMAND`: active, but nothing is demanded of it in this period — no
 *   duty materialized (ponctual or recurring) and no active weekly
 *   structure that would materialize one, conditional policy or not. Neutral:
 *   it blocks neither the generation nor the publication of the others, and
 *   never counts in the completeness of the period;
 * - `INCOMPLETE`: a real demand, not generated yet, and a prerequisite of its
 *   generation is missing (`reasons`). It counts: the period cannot be fully
 *   published while it is so;
 * - `APPLICABLE`: a real demand, ready or already generated.
 *
 * NO_DEMAND never hides a demand: a single duty of the period — required,
 * uncovered, conditional and undetermined, ponctual — makes the line
 * INCOMPLETE or APPLICABLE.
 */
final readonly class LineApplicability
{
    public const INACTIVE = 'INACTIVE';
    public const NOT_IN_PERIOD = 'NOT_IN_PERIOD';
    public const NO_DEMAND = 'NO_DEMAND';
    public const INCOMPLETE = 'INCOMPLETE';
    public const APPLICABLE = 'APPLICABLE';

    /**
     * @param list<string> $reasons why — the evidence of a demand (`DUTIES_MATERIALIZED`, `WEEKLY_STRUCTURE`,
     *                              `CONDITIONAL_POLICY`, `GENERATED`), of its absence (`NO_DUTY`,
     *                              `NO_WEEKLY_STRUCTURE`), or the missing prerequisites (`NO_ACTIVE_RULE_SET`,
     *                              `NO_MEMBER_IN_PERIOD`, `CONDITIONAL_SOURCE_NOT_IN_PERIOD`), or `LINE_INACTIVE`,
     *                              `NO_PERIOD_IN_CYCLE`
     */
    public function __construct(
        public string $state,
        public array $reasons,
        public int $dutyCount = 0,
        public int $recurringPatternCount = 0,
        public bool $conditional = false,
    ) {
    }

    /** It counts in the completeness of the period (APPLICABLE or INCOMPLETE). */
    public function counts(): bool
    {
        return self::APPLICABLE === $this->state || self::INCOMPLETE === $this->state;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'state' => $this->state,
            'reasons' => $this->reasons,
            'dutyCount' => $this->dutyCount,
            'recurringPatternCount' => $this->recurringPatternCount,
            'conditional' => $this->conditional,
        ];
    }
}
