<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * Thrown when the SNAPSHOTTED → SOLVING claim (docs/decisions.md D106)
 * loses a real race: `PlanningGenerationService::generate()` catches
 * Doctrine's `OptimisticLockException` on `PlanningGeneration::$lockVersion`
 * and translates it here — the same "DB constraint is the real guarantee"
 * pattern already used for `PlanningGenerationAlreadySnapshottedException`/
 * `DuplicateDutyAssignmentException`, just backed by an optimistic lock
 * instead of a unique constraint (there is no unique column to lean on for
 * "only one solve in flight", so this is the correct mechanism instead).
 * Also thrown by the up-front status check (DRAFT/SOLVING/COMPLETED/FAILED
 * — anything but SNAPSHOTTED) for the common, non-concurrent case.
 */
final class PlanningGenerationConcurrentSolveException extends \RuntimeException
{
    public function __construct(string $message = 'This PlanningGeneration cannot be solved right now — it is not SNAPSHOTTED, or another solve is already in flight.')
    {
        parent::__construct($message);
    }
}
