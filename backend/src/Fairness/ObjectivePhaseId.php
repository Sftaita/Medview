<?php

declare(strict_types=1);

namespace App\Fairness;

/**
 * The stable identity of one lexicographic objective phase
 * (docs/allocation-algorithm.md §11, docs/planning-solver.md). Backed by a
 * string (not a bare case) so it can key `OptimizationResult::$objectiveValues`/
 * `$optimality` the same way `FairnessDimensionKey::toStringKey()` keys
 * `FairnessDimensionValues` — PHP arrays only accept scalar keys.
 *
 * Scoped to the GENERATE order only (docs/decisions.md D087) — REPAIR's
 * phases (changedAssignmentCount, changeCost, deviation-tolerance guards)
 * are a different metric vocabulary entirely and are not represented here;
 * new cases will be added once REPAIR is actually implemented, never
 * guessed ahead of it.
 *
 * `PARTIAL_COVERAGE_CRITICAL`/`PARTIAL_COVERAGE_TOTAL` (Lot 6C,
 * docs/decisions.md D094) are the two coverage-priority phases that
 * precede the 8 above *only* during a PARTIAL solve
 * (docs/allocation-algorithm.md §10) — never present in
 * `OptimizationProblem::getObjectivePhases()` itself (that list stays the
 * pure GENERATE order regardless of STRICT/PARTIAL), only in a PARTIAL
 * solver result's `objectiveValues`/`optimality`.
 */
enum ObjectivePhaseId: string
{
    case PARTIAL_COVERAGE_CRITICAL = 'PARTIAL_COVERAGE_CRITICAL';
    case PARTIAL_COVERAGE_TOTAL = 'PARTIAL_COVERAGE_TOTAL';
    case MAX_DEVIATION_PRIMARY = 'MAX_DEVIATION_PRIMARY';
    case SUM_DEVIATION_PRIMARY = 'SUM_DEVIATION_PRIMARY';
    case MAX_DEVIATION_SECONDARY = 'MAX_DEVIATION_SECONDARY';
    case SUM_DEVIATION_SECONDARY = 'SUM_DEVIATION_SECONDARY';
    case NAMED_HOLIDAY_REPETITION_PENALTY = 'NAMED_HOLIDAY_REPETITION_PENALTY';
    case SPACING_SCORE = 'SPACING_SCORE';
    case PREFERENCE_SATISFACTION = 'PREFERENCE_SATISFACTION';
    case DETERMINISTIC_TIE_BREAK = 'DETERMINISTIC_TIE_BREAK';
}
