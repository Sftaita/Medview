<?php

declare(strict_types=1);

namespace App\Entity;

/**
 * The two engine operations that run outside the HTTP request
 * (docs/decisions.md D149): "Générer le planning" and "Compléter
 * automatiquement". Same queue, same worker, same states — never one async
 * and the other a long synchronous request.
 */
enum PlanningJobKind: string
{
    case GENERATE = 'GENERATE';
    case COMPLETE = 'COMPLETE';
}
