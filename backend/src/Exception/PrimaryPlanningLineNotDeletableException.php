<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * A Planning must always have exactly one PRIMARY PlanningLine
 * (docs/planning.md §3) — v1 has no promotion mechanism, so deleting it
 * would leave the Planning without one. Simplest correct rule for this
 * version: the PRIMARY line is never deletable (docs/decisions.md D074).
 */
final class PrimaryPlanningLineNotDeletableException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('The PRIMARY PlanningLine of a Planning cannot be deleted.');
    }
}
