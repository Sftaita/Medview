<?php

declare(strict_types=1);

namespace App\Entity;

/**
 * Never chosen by the client (docs/planning-generation.md): AUTO for the
 * solver's rows, MANUAL for a manager's edit (D131), SWAP for a swap two
 * members concluded themselves (docs/decisions.md D178,
 * DutyReassignmentService::swap()).
 */
enum DutyAssignmentSource: string
{
    case AUTO = 'AUTO';
    case MANUAL = 'MANUAL';
    case SWAP = 'SWAP';
}
