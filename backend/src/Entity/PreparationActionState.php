<?php

declare(strict_types=1);

namespace App\Entity;

/**
 * Where a planned announcement or reminder of a period's preparation stands
 * (docs/decisions.md D210). Only SCHEDULED can still change or be cancelled
 * by its manager; the three others are history.
 */
enum PreparationActionState: string
{
    case SCHEDULED = 'SCHEDULED';
    /** Ran at its time: the collection was opened if needed and the emails queued. */
    case EXECUTED = 'EXECUTED';
    /** Its time came but it no longer made sense (period generated, target date passed, superseded…): outcome says why. */
    case SKIPPED = 'SKIPPED';
    /** Withdrawn before its time by the system (the generation was launched). */
    case CANCELLED = 'CANCELLED';
}
