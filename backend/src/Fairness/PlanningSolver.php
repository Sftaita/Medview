<?php

declare(strict_types=1);

namespace App\Fairness;

/**
 * The solver-independent boundary (docs/allocation-algorithm.md §21,
 * docs/decisions.md D031, docs/planning-solver.md): the domain constructs
 * an `OptimizationProblem` and hands it to a `PlanningSolver`, never the
 * other way around, and never anything more specific than this interface —
 * no OR-Tools type, no CP-SAT variable, no solver status enum other than
 * `SolverStatus` appears anywhere near this contract. Substitutable by a
 * test fake (`FakePlanningSolver`), a future `OrToolsPlanningSolver`
 * adapter, or any other solver, without touching the domain.
 *
 * `OrToolsPlanningSolver` (Lot 6B, `src/Solver/`) is the first real
 * implementation — a subprocess adapter, never a PHP binding
 * (docs/decisions.md D031/D090, docs/planning-solver.md).
 */
interface PlanningSolver
{
    public function solve(OptimizationProblem $problem): OptimizationResult;

    /**
     * Whether $problem remains feasible (HARD + POLICY_HARD) once every
     * pairing in $excludedEdges is forbidden — the primitive
     * `GLOBALLY_FORCED` (docs/allocation-algorithm.md §4.3) and a future
     * `ForcedAssignmentAnalyzer` will compose, never implemented itself by
     * this lot. Must never mutate $problem.
     *
     * Returns `SolverStatus`, not `bool` (docs/decisions.md D091, widened
     * from Lot 6A's original `bool` signature before any release consumed
     * it): a bare boolean cannot represent UNKNOWN/ERROR without lying —
     * collapsing either into `false` would read as a proven infeasibility
     * it never was. `OPTIMAL`/`FEASIBLE` both mean "a feasible assignment
     * exists" (this call has no objective, so CP-SAT's own OPTIMAL/FEASIBLE
     * distinction is moot here) ; `UNSATISFIABLE` means proven infeasible ;
     * `UNKNOWN`/`ERROR` mean exactly what they mean everywhere else in this
     * contract — never silently coerced to a boolean by this interface.
     *
     * @param list<DutyAssignmentEdge> $excludedEdges
     */
    public function checkFeasibility(OptimizationProblem $problem, array $excludedEdges): SolverStatus;
}
