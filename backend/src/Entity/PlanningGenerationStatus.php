<?php

declare(strict_types=1);

namespace App\Entity;

/**
 * DRAFT → SNAPSHOTTED → SOLVING → {COMPLETED, FAILED} (docs/decisions.md
 * D106, docs/planning-generation.md §2). `SOLVING` exists even though a
 * solve is fully synchronous in this lot — it is the real, DB-level
 * concurrency claim (`PlanningGeneration` uses Doctrine optimistic locking,
 * `#[ORM\Version]`) that stops the same generation from being solved twice,
 * never just an in-memory `if status === SNAPSHOTTED` check. `COMPLETED`
 * and `FAILED` are both terminal: a generation is never retried in place —
 * a fresh attempt is always a new `PlanningGeneration` row, consistent with
 * this project's "history is never recalculated/overwritten" rule
 * (CLAUDE.md). `COMPLETED` covers both `coverageStatus = COMPLETE` and
 * `= INCOMPLETE` (a real PARTIAL/INCOMPLETE result is a valid business
 * outcome, not a failure) — `FAILED` is reserved for `SolverStatus::UNKNOWN`/
 * `ERROR` or the (contractually possible, currently unreachable)
 * `existingDataConflict` case, where zero `DutyAssignment` rows are ever
 * persisted. See `PlanningGeneration::transitionTo()` for the only legal
 * way to move between these.
 */
enum PlanningGenerationStatus: string
{
    case DRAFT = 'DRAFT';
    case SNAPSHOTTED = 'SNAPSHOTTED';
    case SOLVING = 'SOLVING';
    case COMPLETED = 'COMPLETED';
    case FAILED = 'FAILED';

    /**
     * @return list<self>
     */
    private function allowedTargets(): array
    {
        return match ($this) {
            self::DRAFT => [self::SNAPSHOTTED],
            self::SNAPSHOTTED => [self::SOLVING],
            self::SOLVING => [self::COMPLETED, self::FAILED],
            // Terminal: retrying means creating a new PlanningGeneration,
            // never reopening this one.
            self::COMPLETED, self::FAILED => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return \in_array($target, $this->allowedTargets(), true);
    }
}
