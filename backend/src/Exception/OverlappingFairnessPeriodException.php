<?php

declare(strict_types=1);

namespace App\Exception;

final class OverlappingFairnessPeriodException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('This Team already has a FairnessPeriod covering that date range.');
    }
}
