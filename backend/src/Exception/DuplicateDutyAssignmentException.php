<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * A Duty may never hold two assignments within the same PlanningGeneration
 * (docs/planning-generation.md §16) — the database's unique constraint on
 * (planning_generation_id, duty_id) is the real guarantee; this turns a
 * violation of it into the same clean 409 a sequential duplicate gets,
 * mirroring EmailAlreadyUsedException's race-to-409 pattern (D026).
 */
final class DuplicateDutyAssignmentException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('This Duty already has an assignment within this PlanningGeneration.');
    }
}
