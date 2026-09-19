<?php

declare(strict_types=1);

namespace App\Tests\Fairness;

use App\Fairness\CoverageStatus;
use App\Fairness\OptimizationProblem;
use App\Fairness\OptimizationResult;
use App\Fairness\PlanningSolver;
use App\Fairness\SolverStatus;

/**
 * Test-only `PlanningSolver` (docs/planning-solver.md §Fake solver) — lets
 * consumers of the contract be tested without OR-Tools, which does not
 * exist anywhere in this codebase yet. Deliberately not a pseudo-solver:
 * `$configuredResult`/`$configuredFeasibility` are supplied by the test,
 * never computed here — this project explicitly abandoned "greedy first"
 * (docs/decisions.md D031).
 */
final class FakePlanningSolver implements PlanningSolver
{
    private ?OptimizationProblem $capturedProblem = null;

    /**
     * @var list<array{problem: OptimizationProblem, excludedEdges: array}>
     */
    private array $feasibilityCalls = [];

    public function __construct(
        private OptimizationResult $configuredResult = new OptimizationResult(
            SolverStatus::OPTIMAL,
            null,
            CoverageStatus::COMPLETE,
            [],
            [],
            [],
            [],
            null,
            null,
            null,
        ),
        private SolverStatus $configuredFeasibility = SolverStatus::OPTIMAL,
    ) {
    }

    public function solve(OptimizationProblem $problem): OptimizationResult
    {
        $this->capturedProblem = $problem;

        return $this->configuredResult;
    }

    public function checkFeasibility(OptimizationProblem $problem, array $excludedEdges): SolverStatus
    {
        $this->feasibilityCalls[] = ['problem' => $problem, 'excludedEdges' => $excludedEdges];

        return $this->configuredFeasibility;
    }

    public function getCapturedProblem(): ?OptimizationProblem
    {
        return $this->capturedProblem;
    }

    /**
     * @return list<array{problem: OptimizationProblem, excludedEdges: array}>
     */
    public function getFeasibilityCalls(): array
    {
        return $this->feasibilityCalls;
    }
}
