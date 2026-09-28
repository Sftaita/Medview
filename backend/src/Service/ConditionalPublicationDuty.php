<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Duty;
use App\Entity\PlanningTeamMember;

/**
 * One conditional duty the publication preflight reports on its live state
 * (docs/decisions.md D165): either UNDETERMINED (its source duty has no
 * holder — blocks, never read as "not required") or superfluous (not
 * required any more but still held — a warning, never a blocker).
 */
final readonly class ConditionalPublicationDuty
{
    public function __construct(
        public Duty $duty,
        public ?PlanningTeamMember $holder,
    ) {
    }
}
