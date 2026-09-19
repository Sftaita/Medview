<?php

declare(strict_types=1);

namespace App\Fairness;

/**
 * docs/allocation-algorithm.md §11/§21. REPAIR/SIMULATE are named here
 * because the abstract contract benefits from the complete, stable set
 * (docs/decisions.md) — but OptimizationProblemBuilder in this lot only
 * ever produces GENERATE; nothing in this codebase constructs the other
 * two yet.
 */
enum OptimizationMode: string
{
    case GENERATE = 'GENERATE';
    case REPAIR = 'REPAIR';
    case SIMULATE = 'SIMULATE';
}
