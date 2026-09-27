<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Duty;
use App\Entity\PlanningLine;
use App\Entity\PlanningTeamMember;

/**
 * One Duty of the current calendar and whoever holds it right now
 * (DutyAssignment.current, D131) — null when it is uncovered.
 */
final readonly class CalendarCell
{
    public function __construct(
        public PlanningLine $line,
        public Duty $duty,
        public ?PlanningTeamMember $member,
    ) {
    }
}
