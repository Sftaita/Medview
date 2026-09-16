<?php

declare(strict_types=1);

namespace App\Entity;

/**
 * A RuleSet version's own lifecycle (docs/decisions.md D039). Only DRAFT
 * is ever editable; ACTIVE and RETIRED are permanently immutable — see
 * PlanningRuleSet::updateConfiguration().
 */
enum PlanningRuleSetStatus: string
{
    case DRAFT = 'DRAFT';
    case ACTIVE = 'ACTIVE';
    case RETIRED = 'RETIRED';
}
