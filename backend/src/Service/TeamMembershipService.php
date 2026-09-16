<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ParticipationFactorChangeReason;
use App\Entity\Team;
use App\Entity\TeamMember;
use App\Entity\TeamMemberParticipationPeriod;
use App\Entity\TeamMemberRole;
use App\Entity\User;
use App\Exception\TeamMembershipConflictException;
use App\Repository\TeamMemberParticipationPeriodRepository;
use App\Repository\TeamMemberRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Owns the invariants CLAUDE.md and docs/planning-domain.md require around
 * team membership: a User may join the same Team again after leaving, but
 * never has two open (unended) memberships in it at once, and leaving
 * closes a membership rather than deleting it.
 */
final class TeamMembershipService
{
    public function __construct(
        private readonly TeamMemberRepository $teamMemberRepository,
        private readonly TeamMemberParticipationPeriodRepository $participationPeriodRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Adds a User to a Team, opening both the membership and its first
     * participation period atomically — a TeamMember with no
     * participation history would leave participationFactorAt() undefined
     * from day one, which is never a valid state.
     *
     * @throws TeamMembershipConflictException if the User already has an
     *                                         open membership in this Team
     */
    public function addMember(
        Team $team,
        User $user,
        TeamMemberRole $role,
        \DateTimeImmutable $membershipStart,
        float $initialParticipationFactor = 1.0,
    ): TeamMember {
        if (null !== $this->teamMemberRepository->findOpenMembership($team, $user)) {
            throw new TeamMembershipConflictException();
        }

        $teamMember = new TeamMember($team, $user, $role, $membershipStart);
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
     * TeamMember beyond the end date rather than a stale factor.
     */
    public function endMembership(TeamMember $teamMember, \DateTimeImmutable $membershipEnd): void
    {
        $teamMember->close($membershipEnd);

        $openPeriod = $this->participationPeriodRepository->findOpenPeriod($teamMember);
        $openPeriod?->close($membershipEnd);

        $this->entityManager->flush();
    }
}
