<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\TeamMember;
use App\Entity\TeamMemberNonParticipationPeriod;
use App\Exception\OverlappingNonParticipationPeriodException;
use App\Repository\TeamMemberNonParticipationPeriodRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Owns the overlap invariant for a TeamMember's administrative
 * non-participation windows (docs/availability.md §Chevauchement): two
 * periods of the same TeamMember may never overlap or touch.
 */
final class TeamMemberNonParticipationService
{
    public function __construct(
        private readonly TeamMemberNonParticipationPeriodRepository $repository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @throws OverlappingNonParticipationPeriodException
     */
    public function create(TeamMember $teamMember, \DateTimeImmutable $startsAt, \DateTimeImmutable $endsAt): TeamMemberNonParticipationPeriod
    {
        if ([] !== $this->repository->findOverlappingOrTouchingForTeamMember($teamMember, $startsAt, $endsAt)) {
            throw new OverlappingNonParticipationPeriodException();
        }

        $period = new TeamMemberNonParticipationPeriod($teamMember, $startsAt, $endsAt);
        $this->entityManager->persist($period);
        $this->entityManager->flush();

        return $period;
    }

    /**
     * @throws OverlappingNonParticipationPeriodException
     */
    public function reschedule(TeamMemberNonParticipationPeriod $period, \DateTimeImmutable $startsAt, \DateTimeImmutable $endsAt): void
    {
        $overlapping = $this->repository->findOverlappingOrTouchingForTeamMember(
            $period->getTeamMember(),
            $startsAt,
            $endsAt,
            excluding: $period,
        );

        if ([] !== $overlapping) {
            throw new OverlappingNonParticipationPeriodException();
        }

        $period->reschedule($startsAt, $endsAt);
        $this->entityManager->flush();
    }

    public function delete(TeamMemberNonParticipationPeriod $period): void
    {
        $this->entityManager->remove($period);
        $this->entityManager->flush();
    }
}
