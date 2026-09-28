<?php

declare(strict_types=1);

namespace App\Eligibility;

/**
 * A duty the same person already holds, reduced to what an incompatibility
 * check needs (docs/decisions.md D161): its absolute interval, whether it
 * belongs to the very generation being solved or edited, and — when it
 * does not — the rest thresholds of the generation that owns it.
 *
 * Built from a live DutyAssignment (PersonCommitmentReader, manual
 * reassignment and completion) or from a frozen
 * PlanningSnapshotExternalCommitment (EligibilityService, generation):
 * PersonCommitmentChecker applies one rule to both, so the frozen and the
 * live paths can never disagree.
 */
final readonly class CommitmentInterval
{
    /**
     * @param bool     $sameGeneration         the duty belongs to the generation being solved/edited (same line):
     *                                         CONFLICT / LEGAL_MIN_REST / TEAM_MIN_REST with that generation's
     *                                         own thresholds — otherwise the CROSS_LINE_* reasons apply
     * @param int|null $ownerLegalMinRestHours the owning generation's LEGAL_MIN_REST, null when disabled —
     *                                         only read for a duty of another generation
     * @param int|null $ownerTeamMinRestHours  same for TEAM_MIN_REST
     * @param string   $dutyStableId           the committed duty, for explanation
     * @param string   $lineStableId           the line it belongs to, for explanation
     */
    public function __construct(
        public \DateTimeImmutable $startsAt,
        public \DateTimeImmutable $endsAt,
        public bool $sameGeneration,
        public ?int $ownerLegalMinRestHours,
        public ?int $ownerTeamMinRestHours,
        public string $dutyStableId,
        public string $lineStableId,
    ) {
        if ($endsAt <= $startsAt) {
            throw new \InvalidArgumentException('A commitment interval must end strictly after it starts.');
        }
    }
}
