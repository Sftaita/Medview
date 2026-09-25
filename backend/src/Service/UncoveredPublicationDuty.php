<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Duty;

/**
 * One REQUIRED Duty with no current DutyAssignment (docs/decisions.md
 * D133) — read straight from the live calendar, never the solver's
 * original (possibly since-edited) result.
 */
final readonly class UncoveredPublicationDuty
{
    public function __construct(
        public Duty $duty,
    ) {
    }
}
