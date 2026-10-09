<?php

declare(strict_types=1);

namespace App\Entity;

/**
 * Where a swap request stands (docs/duty-swaps.md §4). Only OPEN is not
 * final: every other status is terminal and never left again.
 *
 * - OPEN: waiting for an answer — the calendar is untouched, the requester
 *   still holds (and is responsible for) their duty;
 * - COMPLETED: one proposal was accepted AND the swap was written
 *   (DutyAssignment SWAP rows) in the same transaction;
 * - REFUSED: an AGREED request whose colleague said no;
 * - CANCELLED: withdrawn by its requester before any swap;
 * - EXPIRED: its duty started before anybody concluded;
 * - OBSOLETE: "Devenu indisponible" — the offered duty is no longer held as
 *   it was when asked (reassigned by a manager, swapped by another request).
 */
enum DutySwapRequestStatus: string
{
    case OPEN = 'OPEN';
    case COMPLETED = 'COMPLETED';
    case REFUSED = 'REFUSED';
    case CANCELLED = 'CANCELLED';
    case EXPIRED = 'EXPIRED';
    case OBSOLETE = 'OBSOLETE';
}
