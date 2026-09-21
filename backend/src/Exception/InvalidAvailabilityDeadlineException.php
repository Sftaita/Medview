<?php

declare(strict_types=1);

namespace App\Exception;

final class InvalidAvailabilityDeadlineException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('The deadline cannot be in the past.');
    }
}
