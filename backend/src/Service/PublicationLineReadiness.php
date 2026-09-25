<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\PlanningLine;
use App\Entity\PlanningPeriodStatus;

/**
 * One active PlanningLine's own readiness (docs/decisions.md D133) —
 * publication is a per-Planning facade over a per-line lifecycle
 * (PlanningPeriodStatus lives on PlanningPeriod, one per line), the exact
 * same shape as generation (D129, PlanningGenerationLauncher).
 */
final readonly class PublicationLineReadiness
{
    public function __construct(
        public PlanningLine $line,
        public PlanningPeriodStatus $periodStatus,
        /** False when there is no COMPLETED generation yet for this line (D125) — nothing to evaluate. */
        public bool $hasGeneration,
    ) {
    }

    /** DRAFT (never generated) or ARCHIVED (already superseded) — structurally not publishable yet/anymore. */
    public function hasReadyStatus(): bool
    {
        return \in_array($this->periodStatus, [PlanningPeriodStatus::GENERATED, PlanningPeriodStatus::VALIDATED, PlanningPeriodStatus::PUBLISHED], true);
    }
}
