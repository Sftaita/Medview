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
 * TEAM_MIN_REST) — the first one found is reported; a candidate is never
 * shown with more than one blocking reason, matching the existing UI
 * vocabulary the frontend already renders (ExclusionReasonLabeler).
 */
final class ReassignmentCandidateService
{
    public function __construct(
        private readonly PlanningGenerationRepository $generationRepository,
        private readonly PlanningTeamMemberRepository $teamMemberRepository,
        private readonly DutyAssignmentRepository $assignmentRepository,
        private readonly UserAvailabilityPeriodRepository $availabilityRepository,
        private readonly TeamMemberNonParticipationPeriodRepository $nonParticipationRepository,
        private readonly RestGapCalculator $restGapCalculator,
        private readonly ExclusionReasonLabeler $reasonLabeler,
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
            $candidates[] = $this->evaluate($generation, $block, $member, $currentTeamMember);
        }

        usort($candidates, static fn (ReassignmentCandidate $a, ReassignmentCandidate $b): int => [$a->lastName, $a->firstName] <=> [$b->lastName, $b->firstName]);

        return new ReassignmentCandidatesView(
            $duty->getGroupInstance()?->getStableId() ? (string) $duty->getGroupInstance()?->getStableId() : null,
            array_map(static fn (Duty $d): ReassignmentBlockDuty => new ReassignmentBlockDuty($d), $block),
            (string) $generation->getStableId(),
            null !== $currentTeamMember ? (string) $currentTeamMember->getStableId() : null,
            $candidates,
        );
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
     * @param list<Duty> $block
     */
    private function evaluate(PlanningGeneration $generation, array $block, PlanningTeamMember $member, ?PlanningTeamMember $currentTeamMember): ReassignmentCandidate
    {
        $isCurrent = $currentTeamMember === $member;
        $reason = $this->firstBlockingReason($generation, $block, $member);

        return new ReassignmentCandidate(
            (string) $member->getStableId(),
            $member->getUser()->getFirstName(),
            $member->getUser()->getLastName(),
            selectable: null === $reason,
            isCurrent: $isCurrent,
            blockingReasons: null !== $reason ? [$this->reasonLabeler->label($reason)] : [],
        );
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
