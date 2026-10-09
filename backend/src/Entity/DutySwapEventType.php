<?php

declare(strict_types=1);

namespace App\Entity;

/**
 * One step of a swap workflow, as recorded in the append-only
 * DutySwapEvent journal (docs/duty-swaps.md §7). The chronology is always
 * read from these rows, never rebuilt from the requests' current status.
 */
enum DutySwapEventType: string
{
    case REQUEST_CREATED = 'REQUEST_CREATED';
    /** The recipients notified (data.recipients) — or the whole line for ALL. */
    case REQUEST_SENT = 'REQUEST_SENT';
    case PROPOSAL_CREATED = 'PROPOSAL_CREATED';
    case PROPOSAL_WITHDRAWN = 'PROPOSAL_WITHDRAWN';
    case PROPOSAL_REFUSED = 'PROPOSAL_REFUSED';
    case PROPOSAL_ACCEPTED = 'PROPOSAL_ACCEPTED';
    case PROPOSAL_NOT_SELECTED = 'PROPOSAL_NOT_SELECTED';
    case PROPOSAL_OBSOLETE = 'PROPOSAL_OBSOLETE';
    case SWAP_COMPLETED = 'SWAP_COMPLETED';
    case REQUEST_REFUSED = 'REQUEST_REFUSED';
    case REQUEST_CANCELLED = 'REQUEST_CANCELLED';
    case REQUEST_EXPIRED = 'REQUEST_EXPIRED';
    case REQUEST_OBSOLETE = 'REQUEST_OBSOLETE';
    /** An acceptance refused by the final revalidation — nothing was written (data.reason). */
    case SWAP_VALIDATION_FAILED = 'SWAP_VALIDATION_FAILED';
}
