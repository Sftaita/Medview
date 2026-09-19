<?php

declare(strict_types=1);

namespace App\Fairness;

/**
 * Whether every REQUIRED DutyUnit ended up assigned — independent of
 * `SolverStatus` (docs/allocation-algorithm.md §10/§21, docs/planning-solver.md):
 * a FEASIBLE (non-optimal) solve can still be COMPLETE, and an OPTIMAL
 * solve of a PARTIAL problem is COMPLETE only if its slack ended up empty.
 */
enum CoverageStatus: string
{
    case COMPLETE = 'COMPLETE';
    case INCOMPLETE = 'INCOMPLETE';
}
