<?php

declare(strict_types=1);

namespace App\Entity;

/**
 * The engine operations that run outside the HTTP request
 * (docs/decisions.md D149): "Générer le planning" and "Compléter
 * automatiquement". Same queue, same worker, same states — never one async
 * and the other a long synchronous request.
 *
 * FILL (docs/decisions.md D222): "Générer les postes non pourvus" / "Générer
 * cette ligne" — the selected lines, one after another, each given its first
 * generation or completed (PlanningLineFillService).
 */
enum PlanningJobKind: string
{
    case GENERATE = 'GENERATE';
    case COMPLETE = 'COMPLETE';
    case FILL = 'FILL';
}
