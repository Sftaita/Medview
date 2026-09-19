<?php

declare(strict_types=1);

namespace App\Fairness;

/**
 * One (DutyUnit, candidate) pairing — a solved assignment in
 * `OptimizationResult::$assignments`, or an excluded pairing passed to
 * `PlanningSolver::checkFeasibility()` (docs/allocation-algorithm.md §21,
 * §4.3). Identified by stable string keys, never by object reference or
 * auto-increment id (D042) — `getStableKey()`/`sourceTeamMemberStableId`,
 * matching the granularity a real future `DutyAssignment.teamMember`
 * actually uses (docs/decisions.md D082's "stint, not person" precedent
 * for exactly this reason).
 */
final readonly class DutyAssignmentEdge
{
    public function __construct(
        public string $dutyUnitStableKey,
        public string $sourceTeamMemberStableId,
    ) {
    }
}
