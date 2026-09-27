<?php

declare(strict_types=1);

namespace App\Entity;

/**
 * Lifecycle of a PlanningJob (docs/decisions.md D149):
 *
 *   QUEUED ──(a worker claims it)──> RUNNING ──> SUCCEEDED | FAILED
 *      └──(abandoned before any worker started it)──────────> FAILED
 *
 * QUEUED is "accepted, nobody has started it": the click alone never puts
 * anything in SOLVING. SUCCEEDED means the operation ran to its end — its
 * coverage (COMPLETE/INCOMPLETE) is part of the recorded outcome, exactly as
 * a COMPLETED PlanningGeneration carries its coverageStatus. FAILED is a
 * technical failure, relaunchable.
 */
enum PlanningJobStatus: string
{
    case QUEUED = 'QUEUED';
    case RUNNING = 'RUNNING';
    case SUCCEEDED = 'SUCCEEDED';
    case FAILED = 'FAILED';

    public function isActive(): bool
    {
        return self::QUEUED === $this || self::RUNNING === $this;
    }
}
