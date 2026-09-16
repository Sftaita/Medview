<?php

declare(strict_types=1);

namespace App\Exception;

final class OverlappingParticipationPeriodException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('This TeamMember already has a participation period covering that date range.');
    }
}
