<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\PlanningCycle;

/**
 * The statistics perimeters (docs/decisions.md D132, D201), for one
 * selected planning period:
 *
 *  - `$currentPeriod`: that period only, one group per line with a
 *    generation, each marked PUBLISHED or UNPUBLISHED (provisional);
 *  - `$cumulative`: the **published** line periods of periods 1 to the
 *    selected one — never a provisional duty;
 *  - `$provisional`: the unpublished line periods with a generation over the
 *    same periods, apart — null when the reader may not see them;
 *  - `$lines`: what the selected period holds, line by line.
 *
 * "This period" is never presented as a complete measure of fairness by
 * itself (§34-§35 of the spec).
 */
final readonly class PlanningStatistics
{
    /**
     * @param list<array{line: string, name: string, publication: string}> $lines the selected period's lines
     */
    public function __construct(
        public StatisticsScope $currentPeriod,
        public StatisticsScope $cumulative,
        public ?StatisticsScope $provisional = null,
        public ?PlanningCycle $cycle = null,
        public array $lines = [],
    ) {
    }
}
