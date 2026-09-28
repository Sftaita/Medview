<?php

declare(strict_types=1);

namespace App\Demand;

/**
 * How a PlanningLine's demand is defined (docs/decisions.md D162).
 *
 * - INDEPENDENT: its own weekly structure alone — the behaviour of every
 *   line before D162, and of any line that has no demand policy at all.
 * - CONDITIONAL_ON_SOURCE_ASSIGNMENT: a duty of this line is only needed
 *   when the person holding the corresponding duty of the source line
 *   triggers it on that weekday (DemandTrigger).
 */
enum DemandMode: string
{
    case INDEPENDENT = 'INDEPENDENT';
    case CONDITIONAL_ON_SOURCE_ASSIGNMENT = 'CONDITIONAL_ON_SOURCE_ASSIGNMENT';

    public function isConditional(): bool
    {
        return self::CONDITIONAL_ON_SOURCE_ASSIGNMENT === $this;
    }
}
