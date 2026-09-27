<?php

declare(strict_types=1);

namespace App\Exception;

use App\Entity\PlanningJob;

/**
 * A generation or a completion is already queued or running on this Planning
 * (docs/decisions.md D149) — the database's partial unique index refused a
 * second active job, whatever the frontend showed.
 */
final class PlanningJobInProgressException extends \RuntimeException
{
    public function __construct(
        public readonly ?PlanningJob $activeJob,
    ) {
        parent::__construct('A generation or a completion is already in progress for this planning.');
    }
}
