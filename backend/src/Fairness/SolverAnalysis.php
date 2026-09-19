<?php

declare(strict_types=1);

namespace App\Fairness;

/**
 * Layer C of the UNSAT model (docs/allocation-algorithm.md §16) — the
 * solver's own native infeasibility analysis (e.g. CP-SAT's infeasible
 * core via assumption literals), if it was actually run.
 *
 * `$available` is always `false` in this lot (docs/decisions.md D096):
 * `bin/cp_sat_solver.py`'s model is built with plain hard constraints
 * (`AddExactlyOne`/`AddAtMostOne`), never assumption literals
 * (`NewBoolVar` + `AddAssumptions`) — extracting a real infeasible core
 * would require restructuring constraint construction to attach an
 * assumption to each one, not done here. The local deterministic
 * diagnostic (`UnsatReport::$structuralDiagnostics`/`$unassignedDuties`)
 * remains valid and complete without it — never a fabricated
 * `infeasibleCore`.
 */
final readonly class SolverAnalysis
{
    /**
     * @param list<string>|null $infeasibleCore
     */
    public function __construct(
        public bool $available,
        public ?array $infeasibleCore = null,
    ) {
    }

    public static function unavailable(): self
    {
        return new self(false, null);
    }
}
