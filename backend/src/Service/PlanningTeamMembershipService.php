<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ParticipationFactorChangeReason;
use App\Entity\PlanningTeam;
use App\Entity\PlanningTeamMember;
use App\Entity\TeamMemberParticipationPeriod;
use App\Entity\TeamMemberRole;
use App\Entity\User;
use App\Exception\PlanningTeamMembershipConflictException;
use App\Repository\PlanningTeamMemberRepository;
use App\Repository\TeamMemberParticipationPeriodRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Owns the invariants CLAUDE.md and docs/planning-domain.md require around
 * team membership: a User may join a PlanningTeam again after leaving it,
 * but never has two open (unended) memberships in the SAME team at once.
 * Since docs/decisions.md D150 (relaxing D080) a User may hold open
 * memberships in several teams of the same Planning — a surgeon can be a
 * holder on the main line and a reinforcement on a secondary line — as
 * well as in teams of different Plannings. Leaving closes a membership
 * rather than deleting it.
 */
final class PlanningTeamMembershipService
{
    public function __construct(
        private readonly PlanningTeamMemberRepository $teamMemberRepository,
        private readonly TeamMemberParticipationPeriodRepository $participationPeriodRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly AvailabilityCollectionService $collectionService,
    ) {
    }

    /**
     * Adds a User to a PlanningTeam, opening both the membership and its
     * first participation period atomically — a PlanningTeamMember with no
     * participation history would leave participationFactorAt() undefined
     * from day one, which is never a valid state.
     *
     * @throws PlanningTeamMembershipConflictException if the User already
     *                                                 has an open
     *                                                 membership in this
     *                                                 team (another team
     *                                                 of the same Planning
     *                                                 is fine, D150)
     */
    public function addMember(
        PlanningTeam $team,
        User $user,
        TeamMemberRole $role,
        \DateTimeImmutable $membershipStart,
        float $initialParticipationFactor = 1.0,
    ): PlanningTeamMember {
        if (null !== $this->teamMemberRepository->findOpenMembership($team, $user)) {
            throw new PlanningTeamMembershipConflictException();
        }

        $teamMember = new PlanningTeamMember($team, $user, $role, $membershipStart);
        $initialPeriod = new TeamMemberParticipationPeriod(
            $teamMember,
            $membershipStart,
            $initialParticipationFactor,
            ParticipationFactorChangeReason::INITIAL,
        );

        $this->entityManager->persist($teamMember);
        $this->entityManager->persist($initialPeriod);
        $this->entityManager->flush();

        // A new participant is expected in every availability collection still open (docs/availability-collection.md §7).
        $this->collectionService->registerMember($team->getPlanning(), $user, $membershipStart);

        return $teamMember;
    }

    /**
     * Closes a membership: sets $membershipEnd and caps the currently
     * open participation period at the same date, so
     * participationFactorAt() correctly returns "no data" for this
     * PlanningTeamMember beyond the end date rather than a stale factor.
     */
    /**
     * "Gestionnaire du planning" (docs/decisions.md D147): the creator grants
     * or withdraws the management right by switching a member between MEMBER
     * and ADMIN — the role PlanningVoter already reads for every planning
     * management action (calendar, completion, generation, publication,
     * availability follow-up). OWNER is never granted nor withdrawn here: it
     * is the creator's own participation role (D123).
     *
     * @throws \InvalidArgumentException when the change touches OWNER
     */
    public function changeManagementRole(PlanningTeamMember $teamMember, TeamMemberRole $role): void
    {
        if (TeamMemberRole::OWNER === $role || TeamMemberRole::OWNER === $teamMember->getRole()) {
            throw new \InvalidArgumentException('Only MEMBER and ADMIN can be granted or withdrawn — OWNER is the creator\'s own role.');
        }

        if (!$teamMember->isCurrentlyOpen()) {
            throw new \InvalidArgumentException('This membership has ended.');
        }

        $teamMember->changeRole($role);
        $this->entityManager->flush();
    }

    public function endMembership(PlanningTeamMember $teamMember, \DateTimeImmutable $membershipEnd): void
    {
        $teamMember->close($membershipEnd);

        $openPeriod = $this->participationPeriodRepository->findOpenPeriod($teamMember);
        $openPeriod?->close($membershipEnd);

        $this->entityManager->flush();

        // No longer expected in the open collections that start once they are gone (docs/availability-collection.md §2)
        // — but availability is collected per person and per Planning: someone who still belongs to another line of
        // this Planning (D150) keeps being expected for as long as that other membership lasts.
        $participationEnd = $this->participationEndInPlanning($teamMember);
        if (null !== $participationEnd) {
            $this->collectionService->withdrawMember($teamMember->getPlanning(), $teamMember->getUser(), $participationEnd);
        }
    }

    /**
     * When the person stops taking part in this Planning altogether: the
     * latest end among all their memberships in it, or null while one of
     * them is still open-ended.
     */
    private function participationEndInPlanning(PlanningTeamMember $teamMember): ?\DateTimeImmutable
    {
        $end = $teamMember->getMembershipEnd();
        foreach ($this->teamMemberRepository->findBy(['planning' => $teamMember->getPlanning(), 'user' => $teamMember->getUser()]) as $membership) {
            $membershipEnd = $membership->getMembershipEnd();
            if (null === $membershipEnd) {
                return null;
            }
            if (null === $end || $membershipEnd > $end) {
                $end = $membershipEnd;
            }
        }

        return $end;
    }
}
