<?php

declare(strict_types=1);

namespace App\Service;

use App\Demand\DemandCalculator;
use App\Demand\SourceHolding;
use App\Entity\Duty;
use App\Entity\PlanningGeneration;
use App\Entity\PlanningGenerationStatus;
use App\Entity\PlanningLine;
use App\Entity\PlanningLineDemandPolicy;
use App\Entity\PlanningPeriod;
use App\Entity\PlanningSnapshot;
use App\Entity\PlanningSnapshotAvailabilityPeriod;
use App\Entity\PlanningSnapshotDemandDecision;
use App\Entity\PlanningSnapshotDemandPolicy;
use App\Entity\PlanningSnapshotDemandTrigger;
use App\Entity\PlanningSnapshotExternalCommitment;
use App\Entity\PlanningSnapshotMember;
use App\Entity\PlanningSnapshotNonParticipationPeriod;
use App\Entity\PlanningSnapshotParticipationPeriod;
use App\Entity\PlanningSnapshotRuleSet;
use App\Entity\PlanningTeamMember;
use App\Exception\ConditionalSourceNotGeneratedException;
use App\Exception\NoActivePlanningRuleSetException;
use App\Exception\PlanningGenerationAlreadySnapshottedException;
use App\Repository\DutyAssignmentRepository;
use App\Repository\DutyRepository;
use App\Repository\PlanningGenerationRepository;
use App\Repository\PlanningLineDemandPolicyRepository;
use App\Repository\PlanningLineRepository;
use App\Repository\PlanningRuleSetRepository;
use App\Repository\PlanningTeamMemberRepository;
use App\Repository\TeamMemberNonParticipationPeriodRepository;
use App\Repository\TeamMemberParticipationPeriodRepository;
use App\Repository\UserAvailabilityPeriodRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Builds the immutable PlanningSnapshot for one PlanningGeneration
 * (docs/planning-generation.md "Snapshot") — current state copied once,
 * never re-read live again afterwards. See CLAUDE.md:
 *
 *   current state → capture snapshot → generation → assignments
 *   future state changes ≠ mutation of old snapshot
 */
final class PlanningSnapshotService
{
    public function __construct(
        private readonly PlanningTeamMemberRepository $teamMemberRepository,
        private readonly TeamMemberParticipationPeriodRepository $participationPeriodRepository,
        private readonly UserAvailabilityPeriodRepository $availabilityPeriodRepository,
        private readonly TeamMemberNonParticipationPeriodRepository $nonParticipationPeriodRepository,
        private readonly PlanningRuleSetRepository $ruleSetRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly PlanningLineRepository $lineRepository,
        private readonly NeighbourCommitmentCollector $neighbourCommitments,
        private readonly PlanningLineDemandPolicyRepository $demandPolicyRepository,
        private readonly PlanningGenerationRepository $generationRepository,
        private readonly DutyAssignmentRepository $assignmentRepository,
        private readonly DutyRepository $dutyRepository,
        private readonly DutyUnitFactory $dutyUnitFactory,
        private readonly DemandCalculator $demandCalculator,
    ) {
    }

    /**
     * @throws PlanningGenerationAlreadySnapshottedException if $generation
     *                                                       already has a
     *                                                       snapshot —
     *                                                       either a
     *                                                       sequential
     *                                                       duplicate, or a
     *                                                       concurrent one
     *                                                       caught via the
     *                                                       database's
     *                                                       unique
     *                                                       constraint (see
     *                                                       docs/planning-generation.md
     *                                                       "Concurrence")
     * @throws NoActivePlanningRuleSetException              if the PlanningTeam has never activated a PlanningRuleSet
     * @throws ConditionalSourceNotGeneratedException        a conditional line whose source line was never generated (D164)
     */
    public function createSnapshot(PlanningGeneration $generation, ?PlannedCalendar $planned = null): PlanningSnapshot
    {
        if (PlanningGenerationStatus::DRAFT !== $generation->getStatus()) {
            throw new PlanningGenerationAlreadySnapshottedException();
        }

        $planningPeriod = $generation->getPlanningPeriod();
        $team = $planningPeriod->getTeam();

        $activeRuleSet = $this->ruleSetRepository->findActive($team);
        if (null === $activeRuleSet) {
            throw new NoActivePlanningRuleSetException();
        }

        // Checked before anything is persisted, like the rule set above: a refusal never leaves half a snapshot behind.
        $demandSource = $this->demandSourceOf($planningPeriod, $planned);

        // TeamMemberParticipationPeriod is DATE-typed, mirroring
        // PlanningPeriod's own bounds directly. UserAvailabilityPeriod and
        // TeamMemberNonParticipationPeriod are TIMESTAMPTZ-typed, so the
        // period's calendar-date bounds are resolved into absolute instants
        // in the PlanningTeam's timezone first — same technique as
        // DutyMaterializationService::resolveInstant().
        $timezone = new \DateTimeZone($team->getTimezone());
        $fromInstant = new \DateTimeImmutable($planningPeriod->getStartsAt()->format('Y-m-d').' 00:00:00', $timezone);
        $toInstant = new \DateTimeImmutable($planningPeriod->getEndsAt()->format('Y-m-d').' 00:00:00', $timezone);

        $snapshot = new PlanningSnapshot($generation);
        $this->entityManager->persist($snapshot);

        $relevantMembers = $this->teamMemberRepository->findIntersecting(
            $team,
            $planningPeriod->getStartsAt(),
            $planningPeriod->getEndsAt(),
        );

        foreach ($relevantMembers as $member) {
            $snapshotMember = new PlanningSnapshotMember(
                $snapshot,
                $member->getStableId(),
                $member->getUser()->getStableId(),
                $member->getMembershipStart(),
                $member->getMembershipEnd(),
                $member->getRole(),
                $member->getUser()->isActive(),
            );
            $this->entityManager->persist($snapshotMember);

            foreach ($this->participationPeriodRepository->findIntersecting($member, $planningPeriod->getStartsAt(), $planningPeriod->getEndsAt()) as $period) {
                $this->entityManager->persist(new PlanningSnapshotParticipationPeriod(
                    $snapshotMember,
                    $period->getValidFrom(),
                    $period->getValidTo(),
                    $period->toFloat(),
                    $period->getChangeReason(),
                ));
            }

            foreach ($this->availabilityPeriodRepository->findIntersecting($member->getUser(), $fromInstant, $toInstant) as $period) {
                $this->entityManager->persist(new PlanningSnapshotAvailabilityPeriod(
                    $snapshotMember,
                    $period->getStableId(),
                    $period->getType(),
                    $period->getStartsAt(),
                    $period->getEndsAt(),
                    $period->getCreatedAt(),
                    $period->getUpdatedAt(),
                    $period->getSource(),
                ));
            }

            foreach ($this->nonParticipationPeriodRepository->findIntersecting($member, $fromInstant, $toInstant) as $period) {
                $this->entityManager->persist(new PlanningSnapshotNonParticipationPeriod(
                    $snapshotMember,
                    $period->getStableId(),
                    $period->getStartsAt(),
                    $period->getEndsAt(),
                    $period->getCreatedAt(),
                    $period->getUpdatedAt(),
                ));
            }
        }

        $this->freezeExternalCommitments($snapshot, $relevantMembers, $planned);
        if (null !== $demandSource) {
            $this->freezeDemand($snapshot, ...$demandSource);
        }

        $this->entityManager->persist(new PlanningSnapshotRuleSet(
            $snapshot,
            $activeRuleSet->getStableId(),
            $activeRuleSet->getVersion(),
            $activeRuleSet->getConfiguration(),
        ));

        $generation->transitionTo(PlanningGenerationStatus::SNAPSHOTTED);

        try {
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException) {
            throw new PlanningGenerationAlreadySnapshottedException();
        }

        return $snapshot;
    }

    /**
     * docs/decisions.md D164 — a conditional line only: the policy in force
     * (version and every trigger, copied), the source line's generation that
     * is read (its most recent COMPLETED one — at a planning-level launch,
     * the one this very launch just produced, sources being solved first),
     * and one decision per conditional duty, computed by the one demand rule
     * (DemandCalculator) from who holds each source duty in that generation
     * right now (DutyAssignment.current). The same source assignments are
     * frozen separately as external commitments (D161): those constrain the
     * people, these explain why each reinforcement exists — two roles, one
     * source of truth read once.
     *
     * A source duty nobody holds leaves its days UNDETERMINED (required =
     * null, reason SOURCE_UNASSIGNED) unless another day of the block is
     * triggered — never recorded as "not required".
     *
     * During a launch (docs/decisions.md D191), a source solved earlier in
     * the same launch is read from what it is about to hold ($planned):
     * that generation, and its planned holders — never its calendar that is
     * about to be replaced.
     *
     * @return array{0: PlanningLineDemandPolicy, 1: PlanningLine, 2: PlanningGeneration, 3: array<int, string>|null}|null
     *                                                                                                                     the policy in force, its source line, the source generation to read and, when it is only planned, its holders by duty id — null for an independent line
     *
     * @throws ConditionalSourceNotGeneratedException
     */
    private function demandSourceOf(PlanningPeriod $period, ?PlannedCalendar $planned = null): ?array
    {
        $line = $this->lineRepository->findOneByPlanningPeriod($period);
        $policy = null !== $line ? $this->demandPolicyRepository->findActiveForLine($line) : null;
        $sourceLine = null !== $policy && $policy->getMode()->isConditional() ? $policy->getSourceLine() : null;
        if (null === $policy || null === $sourceLine) {
            return null;
        }

        // docs/planning-cycles.md §5.6: the source is the source line's period in the SAME cycle — never another one.
        $sourcePeriod = null !== $period->getCycle() ? $sourceLine->periodIn($period->getCycle()) : null;
        if (null !== $sourcePeriod && null !== $planned?->generationOf($sourcePeriod)) {
            return [$policy, $sourceLine, $planned->generationOf($sourcePeriod), $planned->holdersOf($sourcePeriod)];
        }
        $sourceGeneration = (null !== $sourcePeriod ? $this->generationRepository->findMostRecentCompletedByPlanningPeriod($sourcePeriod) : null)
            ?? throw new ConditionalSourceNotGeneratedException();

        return [$policy, $sourceLine, $sourceGeneration, null];
    }

    /**
     * @param array<int, string>|null $plannedHolders the source's planned holders by duty id, when it is only planned (D191)
     */
    private function freezeDemand(PlanningSnapshot $snapshot, PlanningLineDemandPolicy $policy, PlanningLine $sourceLine, PlanningGeneration $sourceGeneration, ?array $plannedHolders = null): void
    {
        $period = $snapshot->getGeneration()->getPlanningPeriod();
        $frozenPolicy = new PlanningSnapshotDemandPolicy($snapshot, $policy->getStableId(), $policy->getVersion(), $sourceLine->getStableId(), $sourceGeneration->getStableId());
        $this->entityManager->persist($frozenPolicy);
        foreach ($policy->getTriggers() as $trigger) {
            $this->entityManager->persist(new PlanningSnapshotDemandTrigger($frozenPolicy, $trigger));
        }

        $holders = $plannedHolders ?? [];
        if (null === $plannedHolders) {
            foreach ($this->assignmentRepository->findForGenerations([$sourceGeneration]) as $assignment) {
                $holders[(int) $assignment->getDuty()->getId()] = (string) $assignment->getTeamMember()->getUser()->getStableId();
            }
        }
        $holdingOf = static fn (Duty $source): SourceHolding => new SourceHolding(true, $holders[(int) $source->getId()] ?? null);
        $rules = $frozenPolicy->toRules();

        foreach ($this->dutyUnitFactory->fromDuties($this->dutyRepository->findByPlanningPeriod($period)) as $unit) {
            if (!$unit->getDuties()[0]->isConditional()) {
                continue;
            }

            $demand = $this->demandCalculator->unit($unit->getDuties(), $rules, $holdingOf);
            foreach ($demand->duties as $dutyDemand) {
                $day = $dutyDemand->ownDay;
                $this->entityManager->persist(new PlanningSnapshotDemandDecision(
                    $snapshot,
                    $dutyDemand->duty,
                    $day->weekday,
                    null !== $day->sourceHolderUserStableId ? Uuid::fromString($day->sourceHolderUserStableId) : null,
                    null !== $day->trigger?->triggerStableId ? Uuid::fromString($day->trigger->triggerStableId) : null,
                    $day->reason,
                    $demand->determined ? $demand->required : null,
                    $dutyDemand->reason,
                ));
            }
        }
    }

    /**
     * docs/decisions.md D161, docs/planning-cycles.md §5.4: the duties this
     * snapshot's people already hold elsewhere — on the lines solved BEFORE
     * this one in the same period, and in every other period of the planning
     * when they can constrain this one (NeighbourCommitmentCollector) —
     * their current assignments right now, frozen as values. A line solved
     * later in the same period is never looked at: at a launch it is about
     * to be regenerated itself, and it is that later line's own snapshot
     * that will see this one's result. The same collection is read again
     * under the calendar lock before the result is written
     * (PlanningGenerationService).
     *
     * @param list<PlanningTeamMember> $members
     */
    private function freezeExternalCommitments(PlanningSnapshot $snapshot, array $members, ?PlannedCalendar $planned = null): void
    {
        $generation = $snapshot->getGeneration();
        $userStableIds = [];
        foreach ($members as $member) {
            $userStableIds[(string) $member->getUser()->getStableId()] = true;
        }

        foreach ($this->neighbourCommitments->collect($generation->getPlanningPeriod(), $generation->getRestPolicy(), $userStableIds, $planned) as $commitment) {
            $ownerPolicy = $commitment->generation->getRestPolicy();
            $this->entityManager->persist(new PlanningSnapshotExternalCommitment(
                $snapshot,
                $commitment->teamMember->getUser()->getStableId(),
                $commitment->line->getStableId(),
                $commitment->generation->getStableId(),
                $commitment->duty->getStableId(),
                $commitment->duty->getStartsAt(),
                $commitment->duty->getEndsAt(),
                $ownerPolicy->legalMinRestEnabled ? $ownerPolicy->legalMinRestHours : null,
                $ownerPolicy->teamMinRestEnabled ? $ownerPolicy->teamMinRestHours : null,
                $commitment->neighbourCycleOf($generation),
            ));
        }
    }
}
