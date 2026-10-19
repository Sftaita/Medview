<?php

declare(strict_types=1);

namespace App\Entity;

/**
 * What one publication did to one line of its period (docs/decisions.md
 * D196): published it for the first time in that period, or announced
 * changes to its already published calendar. A published line whose content
 * did not change is never recorded (no artificial UPDATED).
 */
enum PlanningPublicationLineKind: string
{
    case FIRST_FOR_LINE = 'FIRST_FOR_LINE';
    case UPDATED = 'UPDATED';
}
