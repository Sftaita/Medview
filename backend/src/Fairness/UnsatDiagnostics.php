<?php

declare(strict_types=1);

namespace App\Fairness;

/**
 * Marker for a future, real UNSAT diagnostic report
 * (docs/allocation-algorithm.md §16 "Modèle UNSAT") — deliberately empty in
 * this lot. The full diagnostic model (infeasible cores, POLICY_HARD
 * relaxation candidates, `existingDataConflict` distinction) is explicitly
 * out of scope (docs/planning-solver.md). `OptimizationResult::$diagnostics`
 * is typed `?UnsatDiagnostics` and is always `null` today — kept as an
 * interface rather than `mixed`/an untyped array so a later lot's concrete
 * report type only needs to implement this, without forcing a signature
 * change on `OptimizationResult` itself.
 */
interface UnsatDiagnostics
{
}
