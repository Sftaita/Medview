<?php

declare(strict_types=1);

namespace App\Tests;

use App\Fairness\CoverageStatus;
use App\Fairness\OptimizationProblem;
use App\Fairness\OptimizationResult;
use App\Fairness\PlanningSolver;
use App\Fairness\SolverStatus;
use App\Solver\OrToolsPlanningSolver;

/**
 * Test-only PlanningSolver (config/services.yaml, when@test): the real
 * OR-Tools solver, unless a test asks it to throw — or to run a callback
 * *during* the solve, e.g. a manual calendar edit racing a completion
 * (docs/decisions.md D149). Static switches, reset by every test that sets
 * them (tearDown), so every other test sees the plain real solver.
 */
final class FaultInjectingPlanningSolver implements PlanningSolver
{
    public static ?\Throwable $failWith = null;

    /** @var (callable(): void)|null */
    public static $duringSolve;

    /**
     * docs/decisions.md D164: when it returns true for a problem, the solve
     * ends with SolverStatus::ERROR (a FAILED generation of that line) —
     * without throwing, so the rest of the launch goes on.
     *
     * @var (callable(OptimizationProblem): bool)|null
     */
    public static $errorWhen;

    /** @var list<OptimizationProblem> every problem the solver received since the last reset() */
    public static array $solvedProblems = [];

    public function __construct(
        private readonly OrToolsPlanningSolver $inner,
    ) {
    }

    public static function reset(): void
    {
        self::$failWith = null;
        self::$duringSolve = null;
        self::$errorWhen = null;
        self::$solvedProblems = [];
    }

    public function solve(OptimizationProblem $problem): OptimizationResult
    {
        if (null !== self::$failWith) {
            throw self::$failWith;
        }

        self::$solvedProblems[] = $problem;

        if (null !== self::$errorWhen && (self::$errorWhen)($problem)) {
            return new OptimizationResult(SolverStatus::ERROR, null, CoverageStatus::INCOMPLETE, [], [], [], [], null, null, null);
        }

        $result = $this->inner->solve($problem);

        if (null !== self::$duringSolve) {
            $callback = self::$duringSolve;
            self::$duringSolve = null;
            $callback();
        }

        return $result;
    }

    public function checkFeasibility(OptimizationProblem $problem, array $excludedEdges): SolverStatus
    {
        return $this->inner->checkFeasibility($problem, $excludedEdges);
    }
}
