<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * A snapshot must freeze the rules actually in force (docs/planning-generation.md
 * §PlanningRuleSet) — a generation attempted before the team has ever
 * activated one has nothing to freeze, which is a real precondition
 * failure, not a bug to guard against silently.
 */
final class NoActivePlanningRuleSetException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('This Team has no ACTIVE PlanningRuleSet to snapshot.');
    }
}
