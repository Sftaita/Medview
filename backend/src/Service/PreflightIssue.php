<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\PlanningLine;

final readonly class PreflightIssue
{
    public function __construct(
        public PreflightIssueCode $code,
        /** The line concerned, or null when the issue is planning-wide. */
        public ?PlanningLine $line = null,
    ) {
    }
}
