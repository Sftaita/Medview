<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * `Duty` rows are never duplicated into the immutable `PlanningSnapshot`
 * (docs/planning-generation.md §4 — `Duty` is already immutable by
 * construction, so re-copying it would add no guarantee). That means the
 * live `Duty` list of a `PlanningPeriod` is the one piece of data a real,
 * synchronous CP-SAT solve can still see drift out from under it: a new
 * `Duty` added to the same `PlanningPeriod` while the subprocess is
 * running (docs/decisions.md D106). Every other input (snapshot members,
 * their children, `RestPolicyOptions`) is already frozen and cannot
 * change.
 *
 * `PlanningGenerationService::generate()` compares the canonical
 * `snapshotHash` computed right before solving against one recomputed
 * right before persisting — a mismatch throws this, atomically: nothing is
 * ever persisted against a configuration that became stale mid-solve
 * (docs/allocation-algorithm.md §15 "Concurrence", finally reachable now
 * that a solve has real, non-instant wall-clock duration).
 */
final class StalePlanningGenerationDataException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('The set of Duty rows for this PlanningPeriod changed while this generation was being solved — nothing was persisted. Retry with a fresh solve.');
    }
}
