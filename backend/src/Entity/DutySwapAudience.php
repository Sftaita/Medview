<?php

declare(strict_types=1);

namespace App\Entity;

/**
 * Who may see a swap request and answer it (docs/duty-swaps.md §5):
 * SELECTED — only its DutySwapRequestRecipient rows (an AGREED request is
 * always SELECTED, with its one colleague); ALL — every member of the
 * request's own line (its PlanningTeam), never another line or planning.
 */
enum DutySwapAudience: string
{
    case SELECTED = 'SELECTED';
    case ALL = 'ALL';
}
