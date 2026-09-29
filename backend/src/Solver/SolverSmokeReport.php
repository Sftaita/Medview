<?php

declare(strict_types=1);

namespace App\Solver;

use App\Fairness\OptimizationResult;

/**
 * What SolverSmokeCheck observed: the real OptimizationResult, and every
 * expectation it did not meet (empty when the engine path works).
 */
final readonly class SolverSmokeReport
{
    /**
     * @param list<string> $failures
     */
    public function __construct(
        public OptimizationResult $result,
        public array $failures,
    ) {
    }

    public function isSuccessful(): bool
    {
        return [] === $this->failures;
    }
}
