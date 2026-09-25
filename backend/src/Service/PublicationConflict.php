<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Duty;
use App\Entity\PlanningTeamMember;

/**
 * A currently-assigned Duty whose assignee conflicts with one of their
 * *other* current assignments — overlap, or a rest policy violation if
 * active (docs/decisions.md D133 §6). Never a new constraint
 * implementation: the exact same live check a manual reassignment already
 * runs (ReassignmentCandidateService::firstBlockingReason()).
 */
final readonly class PublicationConflict
{
    public function __construct(
        public Duty $duty,
        public PlanningTeamMember $member,
        public string $reason,
    ) {
    }
}
