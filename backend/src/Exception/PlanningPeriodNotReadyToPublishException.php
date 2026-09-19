<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * `PUBLISHED` requires `coverageStatus = COMPLETE` on the PlanningPeriod's
 * most recent `COMPLETED` `PlanningGeneration` (docs/planning-domain.md §17
 * item 2, closed by docs/decisions.md D106 — this precondition could not
 * be checked before `PlanningGeneration` carried a real `coverageStatus`).
 * Distinct from `InvalidPlanningPeriodTransitionException`: the transition
 * graph itself allows VALIDATED → PUBLISHED, this is a business
 * precondition on top of that, never a state-machine-shape violation.
 */
final class PlanningPeriodNotReadyToPublishException extends \RuntimeException
{
    public function __construct(string $message = 'This PlanningPeriod cannot be published: its most recent generation has no COMPLETE coverage.')
    {
        parent::__construct($message);
    }
}
