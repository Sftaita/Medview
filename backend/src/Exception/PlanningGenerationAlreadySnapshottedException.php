<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * Thrown both by PlanningSnapshotService's up-front status check (the
 * common case) and by its catch of the database's unique constraint on
 * planning_snapshots.planning_generation_id (the concurrent-request race —
 * see docs/planning-generation.md "Concurrence"). Either way the caller
 * gets the same clean, typed conflict instead of a duplicated snapshot or
 * an uncaught DBAL exception.
 */
final class PlanningGenerationAlreadySnapshottedException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('This PlanningGeneration has already been snapshotted.');
    }
}
