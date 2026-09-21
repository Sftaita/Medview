<?php

declare(strict_types=1);

namespace App\Exception;

final class NotAnAvailabilityRespondentException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('You are not an expected respondent of this availability collection.');
    }
}
