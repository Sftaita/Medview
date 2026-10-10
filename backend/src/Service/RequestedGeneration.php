<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\PlanningJob;
use App\Service\SurgicalHub\SurgicalHubFreshnessReport;

/**
 * What PlanningJobService::requestGeneration() accepted: the queued job, and
 * the SurgicalHub check made just before (docs/surgicalhub-integration.md
 * §7.5) — the warnings the launch screen shows.
 */
final readonly class RequestedGeneration
{
    public function __construct(
        public PlanningJob $job,
        public SurgicalHubFreshnessReport $surgicalHub,
    ) {
    }
}
