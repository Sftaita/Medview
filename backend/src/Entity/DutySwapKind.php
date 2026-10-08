<?php

declare(strict_types=1);

namespace App\Entity;

/**
 * How a swap request was started (docs/duty-swaps.md §2, docs/decisions.md D178):
 * - AGREED: "J'ai déjà convenu d'un échange" — the requester names the
 *   colleague AND the colleague's duty; the request carries exactly one
 *   proposal from the start, and the colleague alone decides (accept/refuse);
 * - SEARCH: "Je cherche quelqu'un avec qui échanger" — the recipients (some
 *   colleagues, or the whole line) propose one of their own duties, and the
 *   requester alone decides which one, if any, to accept.
 */
enum DutySwapKind: string
{
    case AGREED = 'AGREED';
    case SEARCH = 'SEARCH';
}
