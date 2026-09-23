<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\PlanningLine;
use App\Entity\PlanningPeriodStatus;

/** What the pipeline needs to run for one line, as observed right now. */
final readonly class LaunchLineReadiness
{
    public function __construct(
        public PlanningLine $line,
        public int $memberCount,
        public int $dutyCount,
        public PlanningPeriodStatus $periodStatus,
        public bool $hasActiveRuleSet,
    ) {
    }
}
