<?php

declare(strict_types=1);

namespace App\Entity;

use App\Fairness\CoverageStatus;
use App\Fairness\SolverStatus;

/**
 * Everything a real solve run produces that `PlanningGeneration` records
 * for audit/reproducibility (docs/decisions.md D106,
 * docs/allocation-algorithm.md §14) — the persistence-facing mirror of
 * `App\Fairness\OptimizationResult`/`SolverMetadata`, exactly the same
 * "value object shaped like the entity's own flat columns" pattern
 * `RestPolicyOptions` established for `$restPolicy` (D105): flat columns
 * on `PlanningGeneration`, reconstructed into this readonly VO by
 * `PlanningGeneration::getSolverRunMetadata()`.
 *
 * Never constructed for a generation that has not actually been solved —
 * `PlanningGeneration::recordSolverRun()` is the only writer, called
 * exactly once, from `PlanningGenerationService::generate()` after a real
 * `PlanningSolver::solve()` call returns.
 */
final readonly class SolverRunMetadata
{
    /**
     * @param array<string, float> $objectiveValues keyed by ObjectivePhaseId::value
     * @param array<string, bool>  $optimality      keyed by ObjectivePhaseId::value
     */
    public function __construct(
        public string $algorithmVersion,
        public string $solverType,
        public string $solverVersion,
        public ?int $solverParameterSetVersion,
        public string $seed,
        public string $snapshotHash,
        public SolverStatus $strictSolverStatus,
        public ?SolverStatus $partialSolverStatus,
        public CoverageStatus $coverageStatus,
        public array $objectiveValues,
        public array $optimality,
        public int $solveDurationMs,
        public bool $timeoutHit,
        public ?string $failureReason,
    ) {
    }
}
