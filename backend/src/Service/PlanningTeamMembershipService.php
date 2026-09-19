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
 * team membership: a User may join a PlanningTeam again after leaving one,
 * but never has two open (unended) memberships within the SAME Planning at
 * once (docs/decisions.md D080, replacing the app-wide D072 rule). A User
 * may however hold simultaneous open memberships in PlanningTeams of
 * *different* Plannings — each Planning is its own self-contained
 * scheduling exercise. Leaving closes a membership rather than deleting it.
 */
final class PlanningTeamMembershipService
{
    public function __construct(
        private readonly PlanningTeamMemberRepository $teamMemberRepository,
        private readonly TeamMemberParticipationPeriodRepository $participationPeriodRepository,
        private readonly EntityManagerInterface $entityManager,
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
     *                                                 Planning, in this
     *                                                 team or another one
     */
    public function addMember(
        PlanningTeam $team,
        User $user,
        TeamMemberRole $role,
        \DateTimeImmutable $membershipStart,
        float $initialParticipationFactor = 1.0,
    ): PlanningTeamMember {
        if (null !== $this->teamMemberRepository->findOpenMembershipForUserInPlanning($team->getPlanning(), $user)) {
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

        return $teamMember;
    }

    /**
     * Closes a membership: sets $membershipEnd and caps the currently
     * open participation period at the same date, so
     * participationFactorAt() correctly returns "no data" for this
     * PlanningTeamMember beyond the end date rather than a stale factor.
     */
    public function endMembership(PlanningTeamMember $teamMember, \DateTimeImmutable $membershipEnd): void
    {
        $teamMember->close($membershipEnd);

        $openPeriod = $this->participationPeriodRepository->findOpenPeriod($teamMember);
        $openPeriod?->close($membershipEnd);

        $this->entityManager->flush();
    }
}
