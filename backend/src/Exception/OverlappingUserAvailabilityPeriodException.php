<?php

declare(strict_types=1);

namespace App\Exception;

final class OverlappingUserAvailabilityPeriodException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('This user already has a calendar period of the same type overlapping or touching that date range.');
    }
}
