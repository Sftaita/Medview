<?php

declare(strict_types=1);

namespace App\Fairness;

/**
 * The abstract solver output (docs/allocation-algorithm.md §21,
 * docs/planning-solver.md) — the domain-facing mirror of `OptimizationProblem`.
 * Nothing in this lot produces a real one outside tests: `FakePlanningSolver`
 * is the only producer (docs/planning-solver.md §Fake solver).
 *
 * `partialSolverStatus` stays `null` unless a STRICT solve returned
 * UNSATISFIABLE and a PARTIAL solve was actually attempted
 * (docs/allocation-algorithm.md §10) — the strict/partial orchestration
 * itself is not implemented by this lot (docs/planning-solver.md
 * §Hors périmètre), this field only gives a future orchestrator somewhere
 * real to put that second result.
 *
 * `snapshotHash` stays `null` in practice: docs/allocation-algorithm.md §14
 * confirms no canonical snapshot hash exists anywhere in the codebase yet
 * — never fabricated here.
 */
final readonly class OptimizationResult
{
    /**
     * @param list<DutyAssignmentEdge> $assignments
     * @param list<UnassignedDutyUnit> $unassignedDuties
     * @param array<string, float>     $objectiveValues  keyed by ObjectivePhaseId::value
     * @param array<string, bool>      $optimality       keyed by ObjectivePhaseId::value — true once proven optimal for that phase, never assumed
     */
    public function __construct(
        public SolverStatus $strictSolverStatus,
        public ?SolverStatus $partialSolverStatus,
        public CoverageStatus $coverageStatus,
        public array $assignments,
        public array $unassignedDuties,
        public array $objectiveValues,
        public array $optimality,
        public ?UnsatDiagnostics $diagnostics,
        public ?string $snapshotHash,
        public ?SolverMetadata $solverMetadata,
    ) {
    }
}
