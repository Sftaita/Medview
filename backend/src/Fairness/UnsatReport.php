<?php

declare(strict_types=1);

namespace App\Fairness;

/**
 * The real UNSAT diagnostic model (docs/allocation-algorithm.md §16),
 * replacing the empty `UnsatDiagnostics` marker interface's placeholder
 * status from Lot 6B — value objects throughout, never a generic
 * `array<string,mixed>`/JSON blob (docs/planning-solver.md).
 *
 * `$strictSolverStatus`/`$partialSolverStatus` intentionally duplicate
 * `OptimizationResult`'s own fields — a self-contained report, readable
 * without the outer object.
 */
final readonly class UnsatReport implements UnsatDiagnostics
{
    /**
     * @param list<UnassignedDutyDiagnostic> $unassignedDuties
     * @param list<StructuralDiagnostic>     $structuralDiagnostics
     * @param list<DiagnosticRelaxation>     $diagnosticRelaxations
     */
    public function __construct(
        public SolverStatus $strictSolverStatus,
        public ?SolverStatus $partialSolverStatus,
        public int $requiredDutyCount,
        public int $assignedDutyCount,
        public array $unassignedDuties,
        public array $structuralDiagnostics,
        public SolverAnalysis $solverAnalysis,
        public array $diagnosticRelaxations,
        public ?ExistingDataConflict $existingDataConflict,
    ) {
    }
}
