<?php

declare(strict_types=1);

namespace App\Entity;

/**
 * A PlanningLineDemandPolicy is created already in force (ACTIVE) and is
 * never edited: the next change creates the next version and RETIRES this
 * one (docs/decisions.md D162). No DRAFT: a demand policy is replaced as a
 * whole by one PUT, there is no intermediate edited state to hold.
 */
enum DemandPolicyStatus: string
{
    case ACTIVE = 'ACTIVE';
    case RETIRED = 'RETIRED';
}
