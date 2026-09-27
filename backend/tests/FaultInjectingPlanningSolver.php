<?php

declare(strict_types=1);

namespace App\Tests;

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

    public function __construct(
        private readonly OrToolsPlanningSolver $inner,
    ) {
    }

    public static function reset(): void
    {
        self::$failWith = null;
        self::$duringSolve = null;
    }

    public function solve(OptimizationProblem $problem): OptimizationResult
    {
        if (null !== self::$failWith) {
            throw self::$failWith;
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
