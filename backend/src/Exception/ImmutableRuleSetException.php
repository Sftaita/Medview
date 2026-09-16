<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * A PlanningRuleSet may only be edited while DRAFT. Once ACTIVE or
 * RETIRED it is permanently immutable — a generation may already have
 * used it, and docs/allocation-algorithm.md is explicit that a RuleSet a
 * generation depends on must never change retroactively (D039).
 */
final class ImmutableRuleSetException extends \LogicException
{
    public function __construct()
    {
        parent::__construct('This PlanningRuleSet is no longer DRAFT and can never be modified again.');
    }
}
