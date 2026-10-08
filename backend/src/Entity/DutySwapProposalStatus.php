<?php

declare(strict_types=1);

namespace App\Entity;

/**
 * Where one proposal stands (docs/duty-swaps.md §4). Only PENDING is not
 * final.
 *
 * - PENDING: waiting for its decider;
 * - ACCEPTED: accepted and applied — at most one per request (partial unique index);
 * - REFUSED: its decider said no;
 * - WITHDRAWN: its author took it back;
 * - NOT_SELECTED: "Non retenu" — another proposal of the same request was accepted;
 * - CANCELLED: its request was cancelled;
 * - EXPIRED: its request expired;
 * - OBSOLETE: "Devenu indisponible" — one of the two duties is no longer held
 *   as it was when proposed.
 */
enum DutySwapProposalStatus: string
{
    case PENDING = 'PENDING';
    case ACCEPTED = 'ACCEPTED';
    case REFUSED = 'REFUSED';
    case WITHDRAWN = 'WITHDRAWN';
    case NOT_SELECTED = 'NOT_SELECTED';
    case CANCELLED = 'CANCELLED';
    case EXPIRED = 'EXPIRED';
    case OBSOLETE = 'OBSOLETE';
}
