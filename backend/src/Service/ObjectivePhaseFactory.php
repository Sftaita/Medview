<?php

declare(strict_types=1);

namespace App\Service;

use App\Fairness\FairnessDimensionClassifier;
use App\Fairness\FairnessDimensionKey;
use App\Fairness\ObjectivePhase;
use App\Fairness\OptimizationMode;

/**
 * Builds the ordered, mode-specific objective phases
 * (docs/allocation-algorithm.md §11, docs/planning-solver.md). GENERATE is
 * the only mode implemented — REPAIR/SIMULATE fail loudly rather than
 * return an invented or partial order (docs/decisions.md D087).
 */
final class ObjectivePhaseFactory
{
    public function __construct(private readonly FairnessDimensionClassifier $classifier)
    {
    }

    /**
     * @param list<FairnessDimensionKey> $applicableDimensions
     *
     * @return list<ObjectivePhase>
     */
    public function forMode(OptimizationMode $mode, array $applicableDimensions): array
    {
        return match ($mode) {
            OptimizationMode::GENERATE => $this->buildGeneratePhases($applicableDimensions),
            OptimizationMode::REPAIR, OptimizationMode::SIMULATE => throw new \LogicException(sprintf('ObjectivePhaseFactory does not support mode %s yet — REPAIR/SIMULATE have a different phase order (docs/allocation-algorithm.md §11) that is not implemented by this lot.', $mode->value)),
        };
    }

    /**
     * @param list<FairnessDimensionKey> $applicableDimensions
     *
     * @return list<ObjectivePhase>
     */
    private function buildGeneratePhases(array $applicableDimensions): array
    {
        $primary = $this->classifier->primaryDimensions($applicableDimensions);
        $secondary = $this->classifier->secondaryDimensions($applicableDimensions);

        // docs/allocation-algorithm.md §11: min-max then sum applies to
        // PRIMARY *and* SECONDARY independently — never merged into one
        // phase per group, never a weighted sum across phases (D032).
        return [
            ObjectivePhase::maxDeviationPrimary($primary),
            ObjectivePhase::sumDeviationPrimary($primary),
            ObjectivePhase::maxDeviationSecondary($secondary),
            ObjectivePhase::sumDeviationSecondary($secondary),
            ObjectivePhase::namedHolidayRepetitionPenalty(),
            ObjectivePhase::spacingScore(),
            ObjectivePhase::preferenceSatisfaction(),
            ObjectivePhase::deterministicTieBreak(),
        ];
    }
}
