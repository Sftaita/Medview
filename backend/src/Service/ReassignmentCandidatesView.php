<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\PlanningTeamMember;

/**
 * The full read model behind the assignment editor (docs/decisions.md
 * D131/D144): which duties the block covers (so the frontend never has to
 * infer atomicity itself), which generation the change would apply to, who
 * holds it now, and who could replace them.
 */
final readonly class ReassignmentCandidatesView
{
    /**
     * @param list<ReassignmentBlockDuty> $blockDuties
     * @param list<ReassignmentCandidate> $candidates  only really assignable members of the duty's own line, the current holder excluded
     */
    public function __construct(
        public ?string $groupInstanceStableId,
        public ?string $groupLabel,
        public array $blockDuties,
        public string $generationStableId,
        /** Who holds this block now, or null — its stableId is the concurrency identity a save must echo back as `expectedCurrentTeamMemberStableId` (docs/decisions.md D131 §Concurrence). */
        public ?PlanningTeamMember $currentTeamMember,
        public array $candidates,
    ) {
    }
}
