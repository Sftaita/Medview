<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\PlanningGeneration;
use App\Entity\PlanningSnapshot;
use App\Entity\SolverParameterSet;
use App\Entity\SolverRunMetadata;
use App\Fairness\OptimizationProblem;
use App\Fairness\OptimizationResult;

/**
 * A generation solved but not written yet (docs/decisions.md D191):
 * PlanningGenerationService::solve() produces it, writeLaunch() writes it —
 * or records why not — once every line of the launch is solved. An
 * unusable outcome is recorded FAILED by solve() itself and never written.
 */
final readonly class SolvedGeneration
{
    public function __construct(
        public PlanningGeneration $generation,
        public PlanningSnapshot $snapshot,
        public OptimizationProblem $problem,
        public OptimizationResult $result,
        public SolverRunMetadata $metadata,
        public SolverParameterSet $parameterSet,
        public string $snapshotHashBefore,
        public bool $usable,
    ) {
    }
}
