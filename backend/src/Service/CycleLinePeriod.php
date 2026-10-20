<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\PlanningCycle;
use App\Entity\PlanningGeneration;
use App\Entity\PlanningLine;
use App\Entity\PlanningPeriod;

/**
 * One line's period in one planning period, as CycleHistoryReader reads it
 * (docs/planning-cycles.md §9, docs/decisions.md D201): its most recent
 * COMPLETED generation — the one holding its current calendar — if any, and
 * whether it is published. Published is the line period's own status
 * (PUBLISHED, or ARCHIVED: published once, never unpublished) — never the
 * status of another line nor of the planning as a whole.
 */
final readonly class CycleLinePeriod
{
    public function __construct(
        public PlanningLine $line,
        public PlanningCycle $cycle,
        public PlanningPeriod $period,
        public ?PlanningGeneration $generation,
        public bool $published,
    ) {
    }
}
