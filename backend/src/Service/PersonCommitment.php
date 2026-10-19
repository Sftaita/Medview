<?php

declare(strict_types=1);

namespace App\Service;

use App\Eligibility\CommitmentInterval;
use App\Entity\Duty;
use App\Entity\PlanningCycle;
use App\Entity\PlanningGeneration;
use App\Entity\PlanningLine;
use App\Entity\PlanningTeamMember;

/**
 * One duty a person currently holds on one line of a Planning — the live
 * counterpart of PlanningSnapshotExternalCommitment (docs/decisions.md
 * D161). Also used for "virtual" commitments: holes a completion is about
 * to fill on a line solved earlier, not written yet
 * (PlanningCompletionService).
 */
final readonly class PersonCommitment
{
    public function __construct(
        public PlanningLine $line,
        public PlanningGeneration $generation,
        public Duty $duty,
        public PlanningTeamMember $teamMember,
    ) {
    }

    /**
     * Seen from $edited: a duty of that same generation is checked with its
     * own thresholds, one of any other generation with the stricter of both
     * (PersonCommitmentChecker).
     */
    public function toIntervalFor(PlanningGeneration $edited): CommitmentInterval
    {
        $ownerPolicy = $this->generation->getRestPolicy();
        $neighbour = $this->neighbourCycleOf($edited);

        return new CommitmentInterval(
            $this->duty->getStartsAt(),
            $this->duty->getEndsAt(),
            $this->generation === $edited,
            $ownerPolicy->legalMinRestEnabled ? $ownerPolicy->legalMinRestHours : null,
            $ownerPolicy->teamMinRestEnabled ? $ownerPolicy->teamMinRestHours : null,
            (string) $this->duty->getStableId(),
            (string) $this->line->getStableId(),
            null !== $neighbour ? (string) $neighbour->getStableId() : null,
            $neighbour?->getNumber(),
            $neighbour?->getStartsAt(),
            $neighbour?->getEndsAt(),
        );
    }

    /** The period this duty belongs to when it is not the period of $edited (docs/planning-cycles.md §5.4). */
    public function neighbourCycleOf(PlanningGeneration $edited): ?PlanningCycle
    {
        $own = $this->generation->getPlanningPeriod()->getCycle();

        return null !== $own && $own !== $edited->getPlanningPeriod()->getCycle() ? $own : null;
    }
}
