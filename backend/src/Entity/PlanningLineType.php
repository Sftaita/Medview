<?php

declare(strict_types=1);

namespace App\Entity;

/**
 * Exactly one PRIMARY PlanningLine per Planning (docs/planning.md §3) —
 * the line created together with the Planning itself. Every line added
 * afterwards is SECONDARY. No promotion/demotion mechanism exists yet
 * (v1 scope, docs/decisions.md D074).
 */
enum PlanningLineType: string
{
    case PRIMARY = 'PRIMARY';
    case SECONDARY = 'SECONDARY';
}
