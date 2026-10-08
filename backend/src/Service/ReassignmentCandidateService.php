<?php

declare(strict_types=1);

namespace App\Service;

use App\Demand\LiveCoverageState;
use App\Eligibility\ExclusionReason;
use App\Entity\Duty;
use App\Entity\DutyAssignment;
use App\Entity\PlanningGeneration;
use App\Entity\PlanningLine;
use App\Entity\PlanningTeamMember;
use App\Entity\UserAvailabilityType;
use App\Exception\DutyNotGeneratedException;
use App\Repository\DutyAssignmentRepository;
use App\Repository\PlanningGenerationRepository;
use App\Repository\PlanningLineRepository;
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
 * set, and every duty the candidate's PERSON currently holds on any active
 * line of the Planning (PersonCommitmentReader, docs/decisions.md D161) —
 * never the state frozen when the generation was first solved. The
 * incompatibility rule itself (PersonCommitmentChecker) and the constraint
 * *thresholds* (RestPolicyOptions, read from the generations concerned —
 * never re-guessed) are shared with the solver's snapshot-time analysis
 * (EligibilityService), so the live and the frozen checks never disagree.
 *
 * Reasons are computed in the same precedence AssignmentConflictAnalyzer
 * uses (structural reasons first, then CONFLICT, then LEGAL_MIN_REST, then
 * TEAM_MIN_REST, each same-line before cross-line) — the first one found is
 * reported.
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
    /** docs/decisions.md D165 — why a conditional block offers no candidate at all. */
    public const COVERAGE_NOT_REQUIRED = 'coverage_not_required';
    public const COVERAGE_UNDETERMINED = 'coverage_undetermined';

    public function __construct(
        private readonly PlanningGenerationRepository $generationRepository,
        private readonly PlanningTeamMemberRepository $teamMemberRepository,
        private readonly DutyAssignmentRepository $assignmentRepository,
        private readonly UserAvailabilityPeriodRepository $availabilityRepository,
        private readonly TeamMemberNonParticipationPeriodRepository $nonParticipationRepository,
        private readonly PlanningSnapshotRepository $snapshotRepository,
        private readonly PlanningSnapshotMemberRepository $snapshotMemberRepository,
        private readonly PersonCommitmentReader $commitmentReader,
        private readonly PersonCommitmentChecker $commitmentChecker,
        private readonly PlanningLineOrder $lineOrder,
        private readonly PlanningLineRepository $lineRepository,
        private readonly LiveDemandViewFactory $demandViewFactory,
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
        // A membership stint is a range of calendar DATES, half-open [membershipStart, membershipEnd) — the same
        // convention as PlanningTeamMember::isActiveAt() and the PlanningPeriod bounds. So the block is looked up by
        // its local dates, [first day, last day + 1), never by its instants: a duty of the first day of a stint
        // starts the evening before in UTC (Europe/Brussels) and would otherwise miss that stint, although the
        // write path (isActiveAt on the local date) accepts it.
        $blockFirstDay = min(array_map(static fn (Duty $d): \DateTimeImmutable => $d->getLocalDate(), $block));
        $blockDayAfter = max(array_map(static fn (Duty $d): \DateTimeImmutable => $d->getLocalDate(), $block))->modify('+1 day');

        // docs/decisions.md D165: a conditional block the live demand does not require (or cannot evaluate) takes
        // no new holder — the list is then empty ON PURPOSE and says why, never an ambiguous empty list.
        $demand = null;
        $coverageState = null;
        $notAssignableReason = null;
        if ($duty->isConditional()) {
            $demand = $this->demandViewFactory->forPlanning($duty->getPlanningPeriod()->getTeam()->getPlanning())->forUnitOf($duty);
            $coverageState = LiveCoverageState::of($demand, null !== $currentTeamMember);
            if (LiveCoverageState::UNDETERMINED === $coverageState) {
                $notAssignableReason = self::COVERAGE_UNDETERMINED;
            } elseif (!$coverageState->isRequired()) {
                $notAssignableReason = self::COVERAGE_NOT_REQUIRED;
            }
        }

        $candidates = [];
        foreach (null === $notAssignableReason ? $this->teamMemberRepository->findIntersecting($team, $blockFirstDay, $blockDayAfter) : [] as $member) {
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
            $demand,
            $coverageState,
            $notAssignableReason,
        );
    }

    /**
     * Why $member cannot take $block right now, or null when they can — the
     * single check behind both the candidate list and the save
     * (DutyReassignmentService), so the two can never disagree: the two
     * structural rules first (own line, generation snapshot), then every
     * live reason of firstBlockingReason().
     *
     * @param list<Duty>             $block
     * @param list<PersonCommitment> $virtualCommitments duties not written yet but about to be, on lines solved
     *                                                   earlier in the same completion (docs/decisions.md D161)
     * @param list<Duty>             $releasedDuties     duties the person holds now but gives up in the same
     *                                                   transaction (a swap, docs/decisions.md D178): never counted
     *                                                   against them — the check is on the calendar AFTER the change
     */
    public function assignabilityError(PlanningGeneration $generation, array $block, PlanningTeamMember $member, array $virtualCommitments = [], array $releasedDuties = []): ?string
    {
        if ($member->getPlanningTeam() !== $block[0]->getTeam()) {
            return self::NOT_A_LINE_MEMBER;
        }

        $snapshot = $this->snapshotRepository->findOneByGeneration($generation);
        if (null === $snapshot || null === $this->snapshotMemberRepository->findOneBySnapshotAndTeamMemberStableId($snapshot, $member->getStableId())) {
            return self::NOT_IN_GENERATION_SNAPSHOT;
        }

        return $this->firstBlockingReason($generation, $block, $member, $virtualCommitments, $releasedDuties)?->value;
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
     * @param list<Duty>             $block
     * @param list<PersonCommitment> $virtualCommitments see assignabilityError()
     * @param list<Duty>             $releasedDuties     see assignabilityError()
     */
    public function firstBlockingReason(PlanningGeneration $generation, array $block, PlanningTeamMember $member, array $virtualCommitments = [], array $releasedDuties = []): ?ExclusionReason
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

        return $this->conflictOrRestReason($generation, $block, $member, $virtualCommitments, $releasedDuties);
    }

    /**
     * Everything the same PERSON currently holds, on every active line of
     * the Planning (docs/decisions.md D161) — not only this member's stint
     * on this line: since D160 the same User may have a stint on each line,
     * and it is the person who cannot be in two places at once. Checked
     * the same way whichever line is being edited (symmetric): a duty of
     * the edited generation gives CONFLICT / LEGAL_MIN_REST / TEAM_MIN_REST
     * with its own thresholds (D131), a duty of another line gives the
     * CROSS_LINE_* reasons with the stricter of both generations'
     * thresholds (PersonCommitmentChecker).
     *
     * @param list<Duty>             $block
     * @param list<PersonCommitment> $virtualCommitments
     * @param list<Duty>             $releasedDuties     given up in the same transaction — absent from the final calendar
     */
    private function conflictOrRestReason(PlanningGeneration $generation, array $block, PlanningTeamMember $member, array $virtualCommitments, array $releasedDuties): ?ExclusionReason
    {
        $user = $member->getUser();
        $blockDutyIds = array_map(static fn (Duty $duty): int => (int) $duty->getId(), [...$block, ...$releasedDuties]);

        $intervals = [];
        foreach ([...$this->commitmentReader->forUser($this->linesToRead($generation), $user), ...$virtualCommitments] as $commitment) {
            if ($commitment->teamMember->getUser() !== $user || \in_array((int) $commitment->duty->getId(), $blockDutyIds, true)) {
                continue;
            }
            $intervals[] = $commitment->toIntervalFor($generation);
        }

        return $this->commitmentChecker->firstViolation($block, $intervals, $generation->getRestPolicy())?->reason;
    }

    /**
     * Every active line of the Planning, plus the edited generation's own
     * line even if it has been deactivated meanwhile — its own duties must
     * always be checked.
     *
     * @return list<PlanningLine>
     */
    private function linesToRead(PlanningGeneration $generation): array
    {
        $line = $this->lineRepository->findOneByPlanningPeriod($generation->getPlanningPeriod());
        if (null === $line) {
            return [];
        }

        $lines = $this->lineOrder->activeInResolutionOrder($line->getPlanning());
        if (!\in_array($line, $lines, true)) {
            $lines[] = $line;
        }

        return $lines;
    }
}
