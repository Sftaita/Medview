<?php

declare(strict_types=1);

namespace App\Fairness;

/**
 * docs/allocation-algorithm.md §21's `solverMetadata: { solverType,
 * solverVersion, solverParameterSetVersion, solveDurationMs, timeoutHit,
 * parameters }`. Nothing in this codebase constructs a real instance yet —
 * no solver exists (docs/planning-solver.md); `FakePlanningSolver` (test
 * only) is the only producer today.
 */
final readonly class SolverMetadata
{
    /**
     * @param array<string, mixed> $parameters opaque solver-specific tuning
     *                                         knobs (e.g. CP-SAT search
     *                                         parameters) — never
     *                                         interpreted by the domain,
     *                                         only carried through for
     *                                         audit/explainability
     */
    public function __construct(
        public string $solverType,
        public string $solverVersion,
        public ?string $solverParameterSetVersion,
        public int $solveDurationMs,
        public bool $timeoutHit,
        public array $parameters = [],
    ) {
    }
}
