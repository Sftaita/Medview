<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Duty;
use App\Entity\PlanningTeamMember;

/**
 * A currently-assigned Duty whose assignee is no longer structurally valid
 * (docs/decisions.md D133 §5) — membership out of range, non-participation,
 * declared unavailable, or an inactive account. Never assumed valid just
 * because a DutyAssignment row exists: re-checked live at preflight time,
 * exactly as a manual reassignment would be (ReassignmentCandidateService).
 */
final readonly class InvalidPublicationAssignment
{
    public function __construct(
        public Duty $duty,
        public PlanningTeamMember $member,
        /** The real, already-translated reason (ExclusionReasonLabeler) — never fabricated. */
        public string $reason,
    ) {
    }
}
