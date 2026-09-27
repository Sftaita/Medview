<?php

declare(strict_types=1);

namespace App\Service;

use App\Eligibility\ExclusionReason;
use App\Entity\Duty;
use App\Entity\DutyAssignment;
use App\Entity\PlanningGeneration;
use App\Entity\PlanningTeamMember;
use App\Entity\UserAvailabilityType;
use App\Exception\DutyNotGeneratedException;
use App\Repository\DutyAssignmentRepository;
use App\Repository\PlanningGenerationRepository;
use App\Repository\PlanningSnapshotMemberRepository;
use App\Repository\PlanningSnapshotRepository;
use App\Repository\PlanningTeamMemberRepository;
use App\Repository\TeamMemberNonParticipationPeriodRepository;
use App\Repository\UserAvailabilityPeriodRepository;

/**
 * Live equivalent of EligibilityService/AssignmentConflictAnalyzer
 * (docs/decisions.md D131) — deliberately a *separate* service, never a
 * reuse of those two: both are hard-wired to a frozen PlanningSnapshot
 * ("never reads live data", EligibilityService's own docblock), which is
 * exactly wrong here. A manual reassignment must see the calendar as it
 * really is right now — today's UserAvailabilityPeriod/
 * TeamMemberNonParticipationPeriod rows, today's live PlanningTeamMember
 * set, and the candidate's other *current* DutyAssignment rows — never the
 * state frozen when the generation was first solved. The pure time
 * arithmetic (RestGapCalculator, Duty::overlapsWith) and the constraint
 * *thresholds* (RestPolicyOptions, read from the generation being edited —
 * never re-guessed) are the only things shared with the solver's own
 * snapshot-time analysis.
 *
 * Reasons are computed in the same precedence AssignmentConflictAnalyzer
 * uses (structural reasons first, then CONFLICT, then LEGAL_MIN_REST, then
 * TEAM_MIN_REST) — the first one found is reported.
 *
 * The candidate list (docs/decisions.md D144) only ever contains members
 * with no blocking reason at all: an impossible candidate is absent, never
 * shown disabled. Two structural rules come first, shared verbatim with the
 * write path (assignabilityError()): the member belongs to the duty's own
 * line (its PlanningTeam — a person of another line never appears and is
 * refused at save time), and to the generation's snapshot (a DutyAssignment
 * always references its frozen PlanningSnapshotMember, D131 — a member who
 * joined after the generation cannot hold one of its duties).
 */
final class ReassignmentCandidateService
{
    public const NOT_A_LINE_MEMBER = 'NOT_A_LINE_MEMBER';
    public const NOT_IN_GENERATION_SNAPSHOT = 'NOT_IN_GENERATION_SNAPSHOT';

    public function __construct(
        private readonly PlanningGenerationRepository $generationRepository,
        private readonly PlanningTeamMemberRepository $teamMemberRepository,
        private readonly DutyAssignmentRepository $assignmentRepository,
        private readonly UserAvailabilityPeriodRepository $availabilityRepository,
        private readonly TeamMemberNonParticipationPeriodRepository $nonParticipationRepository,
        private readonly PlanningSnapshotRepository $snapshotRepository,
        private readonly PlanningSnapshotMemberRepository $snapshotMemberRepository,
        private readonly RestGapCalculator $restGapCalculator,
    ) {
    }

    /**
     * @throws DutyNotGeneratedException when the Duty's line has no
     *                                   current COMPLETED generation (D125) to reassign within
     */
    public function forDuty(Duty $duty): ReassignmentCandidatesView
    {
        $generation = $this->generationRepository->findMostRecentCompletedByPlanningPeriod($duty->getPlanningPeriod());
        if (null === $generation) {
            throw new DutyNotGeneratedException();
        }

        $block = $this->blockDuties($duty);
        $currentByDuty = $this->assignmentRepository->findCurrentByGenerationAndDuties($generation, $block);
        $currentTeamMember = $this->currentBlockTeamMember($block, $currentByDuty);

        $team = $duty->getTeam();
        $blockStart = min(array_map(static fn (Duty $d): \DateTimeImmutable => $d->getStartsAt(), $block));
        $blockEnd = max(array_map(static fn (Duty $d): \DateTimeImmutable => $d->getEndsAt(), $block));

        $candidates = [];
        foreach ($this->teamMemberRepository->findIntersecting($team, $blockStart, $blockEnd) as $member) {
            if ($member === $currentTeamMember) {
                continue;
            }
            if (null !== $this->assignabilityError($generation, $block, $member)) {
                continue;
            }
            $candidates[] = new ReassignmentCandidate((string) $member->getStableId(), $member->getUser()->getFirstName(), $member->getUser()->getLastName());
        }

        usort($candidates, static fn (ReassignmentCandidate $a, ReassignmentCandidate $b): int => [$a->lastName, $a->firstName] <=> [$b->lastName, $b->firstName]);

        return new ReassignmentCandidatesView(
            null !== $duty->getGroupInstance() ? (string) $duty->getGroupInstance()->getStableId() : null,
            $duty->getGroupInstance()?->getPattern()->getName(),
            array_map(static fn (Duty $d): ReassignmentBlockDuty => new ReassignmentBlockDuty($d), $block),
            (string) $generation->getStableId(),
            $currentTeamMember,
            $candidates,
        );
    }

    /**
     * Why $member cannot take $block right now, or null when they can — the
     * single check behind both the candidate list and the save
     * (DutyReassignmentService), so the two can never disagree: the two
     * structural rules first (own line, generation snapshot), then every
     * live reason of firstBlockingReason().
     *
     * @param list<Duty> $block
     */
    public function assignabilityError(PlanningGeneration $generation, array $block, PlanningTeamMember $member): ?string
    {
        if ($member->getPlanningTeam() !== $block[0]->getTeam()) {
            return self::NOT_A_LINE_MEMBER;
        }

        $snapshot = $this->snapshotRepository->findOneByGeneration($generation);
        if (null === $snapshot || null === $this->snapshotMemberRepository->findOneBySnapshotAndTeamMemberStableId($snapshot, $member->getStableId())) {
            return self::NOT_IN_GENERATION_SNAPSHOT;
        }

        return $this->firstBlockingReason($generation, $block, $member)?->value;
    }

    /**
     * @return list<Duty> sorted by local date — the whole DutyGroupInstance
     *                    if $duty belongs to one, or just $duty itself (a block of one)
     */
    public function blockDuties(Duty $duty): array
    {
        $group = $duty->getGroupInstance();
        if (null === $group) {
            return [$duty];
        }

        $duties = $group->getDuties()->toArray();
        usort($duties, static fn (Duty $a, Duty $b): int => $a->getLocalDate() <=> $b->getLocalDate());

        return $duties;
    }

    /**
     * Public on purpose: DutyReassignmentService recomputes this again at
     * save time from fresh data, never trusting the identity the modal
     * captured when it opened (docs/decisions.md D131 §Concurrence).
     *
     * @param list<Duty>                 $block
     * @param array<int, DutyAssignment> $currentByDuty keyed by duty id
     */
    public function currentBlockTeamMember(array $block, array $currentByDuty): ?PlanningTeamMember
    {
        $member = null;
        foreach ($block as $duty) {
            $assignment = $currentByDuty[(int) $duty->getId()] ?? null;
            if (null === $assignment) {
                // Atomicity invariant (docs/planning-generation.md §15): a
                // group is assigned or unassigned as a whole. A mix would be
                // a bug elsewhere — treated here as "no coherent current
                // assignee" rather than silently picking one.
                return null;
            }

            $candidate = $assignment->getTeamMember();
            if (null === $member) {
                $member = $candidate;
            } elseif ($member !== $candidate) {
                return null;
            }
        }

        return $member;
    }

    /**
     * Public on purpose: DutyReassignmentService calls this again at save
     * time to revalidate for real, never trusting what the modal showed
     * when it opened (docs/decisions.md D131 §Revalidation).
     *
     * @param list<Duty> $block
     */
    public function firstBlockingReason(PlanningGeneration $generation, array $block, PlanningTeamMember $member): ?ExclusionReason
    {
        if (!$member->getUser()->isActive()) {
            return ExclusionReason::USER_INACTIVE;
        }

        foreach ($block as $duty) {
            if (!$member->isActiveAt($duty->getLocalDate())) {
                return ExclusionReason::MEMBERSHIP_OUT_OF_RANGE;
            }
        }

        foreach ($block as $duty) {
            foreach ($this->availabilityRepository->findIntersecting($member->getUser(), $duty->getStartsAt(), $duty->getEndsAt()) as $period) {
                if (UserAvailabilityType::UNAVAILABLE === $period->getType()) {
                    return ExclusionReason::UNAVAILABLE;
                }
            }
        }

        foreach ($block as $duty) {
            if ([] !== $this->nonParticipationRepository->findIntersecting($member, $duty->getStartsAt(), $duty->getEndsAt())) {
                return ExclusionReason::NON_PARTICIPATION;
            }
        }

        return $this->conflictOrRestReason($generation, $block, $member);
    }

    /**
     * @param list<Duty> $block
     */
    private function conflictOrRestReason(PlanningGeneration $generation, array $block, PlanningTeamMember $member): ?ExclusionReason
    {
        $otherAssignments = $this->assignmentRepository->findCurrentForTeamMemberInGeneration($generation, $member, excludingDuties: $block);
        if ([] === $otherAssignments) {
            return null;
        }

        $restPolicy = $generation->getRestPolicy();
        $minGapHours = null;

        foreach ($block as $duty) {
            foreach ($otherAssignments as $other) {
                $otherDuty = $other->getDuty();

                if ($duty->overlapsWith($otherDuty)) {
                    return ExclusionReason::CONFLICT;
                }

                $gap = $this->restGapCalculator->gapHours($duty, $otherDuty);
                if (null === $minGapHours || $gap < $minGapHours) {
                    $minGapHours = $gap;
                }
            }
        }

        if (null === $minGapHours) {
            return null;
        }

        if ($restPolicy->legalMinRestEnabled && $minGapHours < $restPolicy->legalMinRestHours) {
            return ExclusionReason::LEGAL_MIN_REST;
        }

        if ($restPolicy->teamMinRestEnabled && $minGapHours < $restPolicy->teamMinRestHours) {
            return ExclusionReason::TEAM_MIN_REST;
        }

        return null;
    }
}
