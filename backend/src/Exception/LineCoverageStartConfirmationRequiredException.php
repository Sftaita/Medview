<?php

declare(strict_types=1);

namespace App\Exception;

use App\Service\LineCoverageStartPlan;

/**
 * docs/decisions.md D233: this coverage start touches duties, and the plan the
 * manager confirmed is not this one (none yet, or the calendar changed since).
 * Nothing was written.
 */
final class LineCoverageStartConfirmationRequiredException extends \RuntimeException
{
    public function __construct(public readonly LineCoverageStartPlan $plan)
    {
        parent::__construct('This coverage start must be confirmed against its current consequences.');
    }
}
