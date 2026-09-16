<?php

declare(strict_types=1);

namespace App\Exception;

final class OverlappingNonParticipationPeriodException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('This TeamMember already has a non-participation period overlapping or touching that date range.');
    }
}
