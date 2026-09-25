<?php

declare(strict_types=1);

namespace App\Service;

/**
 * The full read model behind the reassignment modal (docs/decisions.md
 * D131): which duties the block covers (so the frontend never has to
 * infer atomicity itself, §41), which generation the change would apply
 * to, and the live candidate pool.
 */
final readonly class ReassignmentCandidatesView
{
    /**
     * @param list<ReassignmentBlockDuty> $blockDuties
     * @param list<ReassignmentCandidate> $candidates
     */
    public function __construct(
        public ?string $groupInstanceStableId,
        public array $blockDuties,
        public string $generationStableId,
        /** The teamMemberStableId currently holding this block, or null — the concurrency identity a save must echo back as `expectedCurrentTeamMemberStableId` (docs/decisions.md D131 §Concurrence). */
        public ?string $currentTeamMemberStableId,
        public array $candidates,
    ) {
    }
}
