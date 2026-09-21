<?php

declare(strict_types=1);

namespace App\Exception;

final class NoNewPlanningRangeException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('The requested range adds no new date to the planning: nothing to collect.');
    }
}
