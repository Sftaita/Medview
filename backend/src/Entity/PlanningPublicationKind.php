<?php

declare(strict_types=1);

namespace App\Entity;

/**
 * Which kind of diffusion a PlanningPublication records (docs/decisions.md
 * D143): the very first "Publier le planning" (every participant receives
 * the full PDF) or a later "Republier les modifications" (only the people
 * concerned by the changed dates are told what changed).
 */
enum PlanningPublicationKind: string
{
    case FIRST = 'FIRST';
    case UPDATE = 'UPDATE';
}
