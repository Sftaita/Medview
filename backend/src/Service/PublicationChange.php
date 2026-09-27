<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Duty;
use App\Entity\PlanningLine;
use App\Entity\PlanningTeamMember;

/**
 * One Duty whose current holder differs from what the last publication
 * diffused (docs/decisions.md D143). `$before`/`$after` null = uncovered
 * (a removal without replacement has `$after === null`).
 */
final readonly class PublicationChange
{
    public function __construct(
        public PlanningLine $line,
        public Duty $duty,
        public ?PlanningTeamMember $before,
        public ?PlanningTeamMember $after,
    ) {
    }
}
