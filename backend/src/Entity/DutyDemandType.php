<?php

declare(strict_types=1);

namespace App\Entity;

/**
 * docs/allocation-algorithm.md §5/§21: OPTIONAL duties never enter
 * requiredDemand, never participate in the strict coverage constraint,
 * and can never cause a coverage shortfall (UNSAT).
 *
 * CONDITIONAL (docs/decisions.md D163): a duty of a conditional line
 * (CONDITIONAL_ON_SOURCE_ASSIGNMENT, D162). Whether it is needed is NOT a
 * property of the duty: it depends on who holds its coverage source
 * (Duty::getCoverageSource()) — a DemandView answer, recomputed from the
 * calendar (live) or read from a generation's frozen decisions (snapshot).
 * The duty itself stays CONDITIONAL forever: never rewritten to REQUIRED
 * or OPTIONAL when its source holder changes.
 */
enum DutyDemandType: string
{
    case REQUIRED = 'REQUIRED';
    case OPTIONAL = 'OPTIONAL';
    case CONDITIONAL = 'CONDITIONAL';
}
