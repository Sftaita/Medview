<?php

declare(strict_types=1);

namespace App\Fairness;

/**
 * docs/allocation-algorithm.md §10/§21. Three genuinely distinct concepts,
 * never conflated (docs/planning-solver.md):
 *
 *   UNSATISFIABLE — the solver proved no solution exists for this problem.
 *   UNKNOWN       — the solver could not conclude either way (e.g. budget
 *                   exhausted before proof) — never treated as UNSAT; the
 *                   caller must retry or surface "inconclusive", never
 *                   silently fall back to PARTIAL on this basis alone
 *                   (docs/allocation-algorithm.md §10 step 5).
 *   ERROR         — a technical failure of the solver itself, never a
 *                   metric result — never confused with a business UNSAT.
 */
enum SolverStatus: string
{
    case OPTIMAL = 'OPTIMAL';
    case FEASIBLE = 'FEASIBLE';
    case UNSATISFIABLE = 'UNSATISFIABLE';
    case UNKNOWN = 'UNKNOWN';
    case ERROR = 'ERROR';
}
