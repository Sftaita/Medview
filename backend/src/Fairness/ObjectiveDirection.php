<?php

declare(strict_types=1);

namespace App\Fairness;

/**
 * Whether a phase's metric is driven down or up (docs/allocation-algorithm.md
 * §11). Never a signed weight/coefficient — D032 already rules out any
 * numeric weighting between phases; this only says which way one isolated
 * phase's own metric moves.
 */
enum ObjectiveDirection: string
{
    case MINIMIZE = 'MINIMIZE';
    case MAXIMIZE = 'MAXIMIZE';
}
