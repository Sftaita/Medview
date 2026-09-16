<?php

declare(strict_types=1);

namespace App\Entity;

/**
 * Never chosen by the client (docs/planning-generation.md) — the server
 * always forces MANUAL for the one write path this lot exposes.
 * AUTO/SWAP are reserved for the future generation engine and swap
 * workflow (docs/allocation-algorithm.md §19), out of scope here.
 */
enum DutyAssignmentSource: string
{
    case AUTO = 'AUTO';
    case MANUAL = 'MANUAL';
    case SWAP = 'SWAP';
}
