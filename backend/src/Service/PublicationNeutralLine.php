<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\PlanningLine;
use App\Entity\PlanningPeriodStatus;

/**
 * An active line with nothing demanded in the period (docs/decisions.md D194,
 * LineApplicability::NO_DEMAND): neutral — it neither blocks a publication
 * nor is published, and keeps its status (a DRAFT line stays DRAFT). Listed
 * apart, with why, so it is never mistaken for a line still to generate.
 */
final readonly class PublicationNeutralLine
{
    public function __construct(
        public PlanningLine $line,
        public PlanningPeriodStatus $periodStatus,
        public LineApplicability $applicability,
    ) {
    }
}
