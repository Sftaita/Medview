<?php

declare(strict_types=1);

namespace App\Fairness;

/**
 * docs/allocation-algorithm.md §10.5/§16: a conflict baked into data that
 * already existed *before* this solve — never repairable by choosing
 * which new `unassigned[d]` to leave empty, because it involves nothing
 * the PARTIAL solve's own decision variables control.
 *
 * `LOCKED_ASSIGNMENT_CONTRADICTION` is the only case named by the spec's
 * own example ("verrouillages existants en conflit LEGAL_MIN_REST") — and
 * is, today, entirely unreachable: it requires two real, conflicting
 * fixed/locked assignments, and `fixedAssignments` does not exist on
 * `OptimizationProblem` yet (docs/decisions.md D090). Kept as the one
 * named case the contract must be able to represent, never exercised by
 * any real code path or test in this lot (docs/decisions.md D098).
 */
enum ExistingDataConflictType: string
{
    case LOCKED_ASSIGNMENT_CONTRADICTION = 'LOCKED_ASSIGNMENT_CONTRADICTION';
}
