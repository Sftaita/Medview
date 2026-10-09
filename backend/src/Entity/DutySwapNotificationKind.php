<?php

declare(strict_types=1);

namespace App\Entity;

/**
 * The emails of the swap workflow (docs/duty-swaps.md §8). Every kind
 * except SWAP_CONFIRMED is about something still pending, and repeats that
 * the recipient's duties are unchanged until a swap is confirmed.
 */
enum DutySwapNotificationKind: string
{
    /** AGREED: to the colleague — "Paul vous propose l'échange convenu". */
    case AGREED_PROPOSAL_RECEIVED = 'AGREED_PROPOSAL_RECEIVED';
    /** SEARCH: to each recipient (or line member) — "Paul cherche à échanger sa garde". */
    case REQUEST_RECEIVED = 'REQUEST_RECEIVED';
    /** SEARCH: to the requester — "Pierre vous propose sa garde". */
    case PROPOSAL_RECEIVED = 'PROPOSAL_RECEIVED';
    /** To the author of a refused proposal. */
    case PROPOSAL_REFUSED = 'PROPOSAL_REFUSED';
    /** To each of the two participants, once the swap is written. */
    case SWAP_CONFIRMED = 'SWAP_CONFIRMED';
}
