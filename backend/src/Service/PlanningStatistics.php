<?php

declare(strict_types=1);

namespace App\Service;

/**
 * The two statistics perimeters a manager needs (docs/decisions.md D132,
 * §34-§35 of the spec): "this period" (what the engine/manager just
 * produced) is never presented as a complete measure of fairness by
 * itself — "cumulative" is the whole Planning's real current state.
 */
final readonly class PlanningStatistics
{
    public function __construct(
        public StatisticsScope $currentPeriod,
        public StatisticsScope $cumulative,
    ) {
    }
}
