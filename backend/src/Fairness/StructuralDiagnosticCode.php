<?php

declare(strict_types=1);

namespace App\Fairness;

/**
 * Deterministic, precalculated capacity diagnoses
 * (docs/allocation-algorithm.md §16 "structuralDiagnostics", layer A —
 * "précalcul de capacité déterministe").
 *
 * `NO_ELIGIBLE_CANDIDATE` — a REQUIRED unit left unassigned with zero
 * eligible candidates in the `EligibilityMatrix` is always, trivially,
 * unambiguously true — no algorithm needed beyond reading the matrix.
 *
 * `INSUFFICIENT_ELIGIBLE_CAPACITY` (Lot 6D, docs/decisions.md D102) — a
 * narrow, mathematically exact case only: two REQUIRED units in
 * `AssignmentConflict` (CONFLICT or TEAM_MIN_REST) for a candidate C,
 * where C is the *only* eligible candidate for *both* units. Both units
 * can then never be covered together, proven directly, no approximate
 * capacity/matching algorithm involved. The general case (a Hall's
 * theorem violation across an arbitrary group of units and a larger
 * shared candidate pool) is **not** covered — that needs a real
 * bipartite-matching argument, not implemented anywhere in this
 * codebase; producing it via approximation would risk a wrong or
 * misleading diagnosis, worse than not diagnosing at all.
 */
enum StructuralDiagnosticCode: string
{
    case NO_ELIGIBLE_CANDIDATE = 'NO_ELIGIBLE_CANDIDATE';
    case INSUFFICIENT_ELIGIBLE_CAPACITY = 'INSUFFICIENT_ELIGIBLE_CAPACITY';
}
