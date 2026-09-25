<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * Every active PlanningLine of this Planning is already PUBLISHED
 * (docs/decisions.md D133 §Idempotence) — a repeated POST /publish is
 * refused with a clear, distinct signal rather than silently
 * re-transitioning (which PlanningPeriodStatus::canTransitionTo() would
 * refuse anyway, but this gives the caller an honest, idempotent reason
 * instead of a generic "not publishable").
 */
final class PlanningAlreadyPublishedException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('This Planning is already published.');
    }
}
