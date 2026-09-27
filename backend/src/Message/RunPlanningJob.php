<?php

declare(strict_types=1);

namespace App\Message;

/**
 * "Run this PlanningJob" (docs/decisions.md D149). Carries only the job id:
 * everything the worker needs (planning, kind, requester, rest policy) is
 * persisted on the job itself, so a message redelivered later — or replayed
 * from the failure transport — can never run with stale parameters.
 */
final readonly class RunPlanningJob
{
    public function __construct(
        public int $jobId,
    ) {
    }
}
