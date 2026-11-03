<?php

declare(strict_types=1);

namespace App\Exception;

use App\Service\PeriodDateChangePlan;

/**
 * docs/decisions.md D225: these new dates touch generated duties or a
 * generated neighbour, and the plan the manager confirmed is not this one
 * (none yet, or the calendar changed since). Nothing was written.
 */
final class PeriodDateChangeConfirmationRequiredException extends \RuntimeException
{
    public function __construct(public readonly PeriodDateChangePlan $plan)
    {
        parent::__construct('These new dates must be confirmed against their current consequences.');
    }
}
