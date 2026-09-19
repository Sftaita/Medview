<?php

declare(strict_types=1);

namespace App\Fairness;

use App\Entity\PlanningSnapshotMember;

/**
 * One real person, scoped to one snapshot — the candidate identity a
 * FairnessContext actually reasons about (docs/fairness.md §Identité
 * candidat, docs/decisions.md D082 Audit A). Deliberately **not** keyed by
 * `sourceTeamMemberStableId` (one row per membership *stint*, as
 * EligibilityMatrix is) — a User with two stints intersecting the same
 * snapshot (left and rejoined the team within the window) must still be
 * exactly one fairness candidate, never counted twice.
 *
 * $stints holds every PlanningSnapshotMember belonging to this User in
 * this snapshot — never overlapping in time by construction of the live
 * membership domain (PlanningTeamMembershipService never opens a second
 * stint while one is open), so at most one stint ever covers any given
 * Duty's date.
 */
final readonly class FairnessCandidate
{
    /**
     * @param non-empty-list<PlanningSnapshotMember> $stints
     */
    public function __construct(
        public string $sourceUserStableId,
        public array $stints,
    ) {
    }

    /**
     * The stint (if any) covering $localDate — never more than one, see
     * class docblock.
     */
    public function stintCovering(\DateTimeImmutable $localDate): ?PlanningSnapshotMember
    {
        foreach ($this->stints as $stint) {
            if ($stint->coversLocalDate($localDate)) {
                return $stint;
            }
        }

        return null;
    }
}
