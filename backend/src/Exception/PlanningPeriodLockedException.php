<?php

declare(strict_types=1);

namespace App\Exception;

final class PlanningPeriodLockedException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('A line of this planning is already validated or published: extending it is not supported yet.');
    }
}
