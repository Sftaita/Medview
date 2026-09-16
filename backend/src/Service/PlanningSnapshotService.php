<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\PlanningGeneration;
use App\Entity\PlanningGenerationStatus;
use App\Entity\PlanningSnapshot;
use App\Entity\PlanningSnapshotAvailabilityPeriod;
use App\Entity\PlanningSnapshotMember;
use App\Entity\PlanningSnapshotNonParticipationPeriod;
use App\Entity\PlanningSnapshotParticipationPeriod;
use App\Entity\PlanningSnapshotRuleSet;
use App\Exception\NoActivePlanningRuleSetException;
use App\Exception\PlanningGenerationAlreadySnapshottedException;
use App\Repository\PlanningRuleSetRepository;
use App\Repository\TeamMemberNonParticipationPeriodRepository;
use App\Repository\TeamMemberParticipationPeriodRepository;
use App\Repository\TeamMemberRepository;
use App\Repository\UserAvailabilityPeriodRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;

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
        private readonly TeamMemberRepository $teamMemberRepository,
        private readonly TeamMemberParticipationPeriodRepository $participationPeriodRepository,
        private readonly UserAvailabilityPeriodRepository $availabilityPeriodRepository,
        private readonly TeamMemberNonParticipationPeriodRepository $nonParticipationPeriodRepository,
        private readonly PlanningRuleSetRepository $ruleSetRepository,
        private readonly EntityManagerInterface $entityManager,
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
     * @throws NoActivePlanningRuleSetException              if the Team has never activated a PlanningRuleSet
     */
    public function createSnapshot(PlanningGeneration $generation): PlanningSnapshot
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

        // TeamMemberParticipationPeriod is DATE-typed, mirroring
        // PlanningPeriod's own bounds directly. UserAvailabilityPeriod and
        // TeamMemberNonParticipationPeriod are TIMESTAMPTZ-typed, so the
        // period's calendar-date bounds are resolved into absolute instants
        // in the Team's timezone first — same technique as
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
}
