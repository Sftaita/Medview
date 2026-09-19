<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * A solve needs a real, versioned timeout/worker-count (docs/decisions.md
 * D106) — never a hidden literal. If the system has never been seeded with
 * a `SolverParameterSet`, that is a genuine precondition failure, not
 * something to silently default around.
 */
final class NoSolverParameterSetException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('No SolverParameterSet exists — the system has never been seeded with one.');
    }
}
