<?php

declare(strict_types=1);

namespace App\Entity;

/**
 * docs/allocation-algorithm.md §5/§21: OPTIONAL duties never enter
 * requiredDemand, never participate in the strict coverage constraint,
 * and can never cause a coverage shortfall (UNSAT).
 */
enum DutyDemandType: string
{
    case REQUIRED = 'REQUIRED';
    case OPTIONAL = 'OPTIONAL';
}
