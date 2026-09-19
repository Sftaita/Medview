<?php

declare(strict_types=1);

namespace App\Fairness;

/**
 * One lexicographic objective phase (docs/allocation-algorithm.md §11,
 * docs/planning-solver.md) — a typed replacement for a generic
 * `{phase: int, type: string, payload: array}` shape. `$id` and
 * `$direction` are always paired correctly by construction (private
 * constructor + one named factory per real phase below) — it is
 * structurally impossible to build e.g. a MAX_DEVIATION_PRIMARY phase with
 * MAXIMIZE direction.
 *
 * `$dimensions` is empty for the four phases whose metric is not a
 * FairnessDimensionKey quantity (named holiday penalty, spacing, preference
 * satisfaction, tie-break) — never a null/omitted field, so every phase has
 * the same shape regardless of whether dimensions apply.
 *
 * Never mutated after construction (readonly + promoted properties) — a
 * caller cannot push into `$dimensions` from outside (`Cannot modify
 * readonly property` fatal error), which is what
 * ObjectivePhaseFactoryTest's immutability tests rely on.
 */
final readonly class ObjectivePhase
{
    /**
     * @param list<FairnessDimensionKey> $dimensions
     */
    private function __construct(
        public ObjectivePhaseId $id,
        public ObjectiveDirection $direction,
        public array $dimensions,
    ) {
    }

    /**
     * @param list<FairnessDimensionKey> $primaryDimensions
     */
    public static function maxDeviationPrimary(array $primaryDimensions): self
    {
        return new self(ObjectivePhaseId::MAX_DEVIATION_PRIMARY, ObjectiveDirection::MINIMIZE, $primaryDimensions);
    }

    /**
     * @param list<FairnessDimensionKey> $primaryDimensions
     */
    public static function sumDeviationPrimary(array $primaryDimensions): self
    {
        return new self(ObjectivePhaseId::SUM_DEVIATION_PRIMARY, ObjectiveDirection::MINIMIZE, $primaryDimensions);
    }

    /**
     * @param list<FairnessDimensionKey> $secondaryDimensions
     */
    public static function maxDeviationSecondary(array $secondaryDimensions): self
    {
        return new self(ObjectivePhaseId::MAX_DEVIATION_SECONDARY, ObjectiveDirection::MINIMIZE, $secondaryDimensions);
    }

    /**
     * @param list<FairnessDimensionKey> $secondaryDimensions
     */
    public static function sumDeviationSecondary(array $secondaryDimensions): self
    {
        return new self(ObjectivePhaseId::SUM_DEVIATION_SECONDARY, ObjectiveDirection::MINIMIZE, $secondaryDimensions);
    }

    public static function namedHolidayRepetitionPenalty(): self
    {
        return new self(ObjectivePhaseId::NAMED_HOLIDAY_REPETITION_PENALTY, ObjectiveDirection::MINIMIZE, []);
    }

    public static function spacingScore(): self
    {
        return new self(ObjectivePhaseId::SPACING_SCORE, ObjectiveDirection::MAXIMIZE, []);
    }

    public static function preferenceSatisfaction(): self
    {
        return new self(ObjectivePhaseId::PREFERENCE_SATISFACTION, ObjectiveDirection::MAXIMIZE, []);
    }

    /**
     * Direction is a convention, not a spec requirement (docs/decisions.md
     * D088): the tie-break has no natural "bigger is better" — MINIMIZE
     * here means "prefer the smallest tieBreakKey", an arbitrary but fixed
     * and documented rule, exactly the kind of thing a future adapter must
     * be able to read off this phase rather than assume.
     */
    public static function deterministicTieBreak(): self
    {
        return new self(ObjectivePhaseId::DETERMINISTIC_TIE_BREAK, ObjectiveDirection::MINIMIZE, []);
    }
}
