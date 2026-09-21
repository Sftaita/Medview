<?php

declare(strict_types=1);

namespace App\Exception;

final class PlanningRangeShrinkException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('A planning can only be extended: the new range must contain the current one.');
    }
}
