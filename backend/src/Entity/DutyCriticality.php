<?php

declare(strict_types=1);

namespace App\Entity;

/**
 * docs/allocation-algorithm.md §10: drives the lexicographic priority of
 * the partial diagnostic solve (leaving a CRITICAL duty unassigned is
 * minimized before leaving any STANDARD duty unassigned).
 */
enum DutyCriticality: string
{
    case STANDARD = 'STANDARD';
    case CRITICAL = 'CRITICAL';
}
