<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PlanningTeamMember;
use App\Entity\TeamMemberNonParticipationPeriod;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<TeamMemberNonParticipationPeriod>
 */
class TeamMemberNonParticipationPeriodRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TeamMemberNonParticipationPeriod::class);
    }

    public function findOneByStableId(string $stableId): ?TeamMemberNonParticipationPeriod
    {
        try {
            $uuid = Uuid::fromString($stableId);
        } catch (\InvalidArgumentException) {
            return null;
        }

        return $this->findOneBy(['stableId' => $uuid]);
    }

    /**
     * @return list<TeamMemberNonParticipationPeriod>
     */
    public function findByTeamMember(PlanningTeamMember $teamMember): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.teamMember = :teamMember')
            ->setParameter('teamMember', $teamMember)
            ->orderBy('p.startsAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Same overlap-or-touch policy as
     * UserAvailabilityPeriodRepository::findOverlappingOrTouchingForUser()
     * — see that method's docblock.
     *
     * @return list<TeamMemberNonParticipationPeriod>
     */
    public function findOverlappingOrTouchingForTeamMember(
        PlanningTeamMember $teamMember,
        \DateTimeImmutable $startsAt,
        \DateTimeImmutable $endsAt,
        ?TeamMemberNonParticipationPeriod $excluding = null,
    ): array {
        $qb = $this->createQueryBuilder('p')
            ->andWhere('p.teamMember = :teamMember')
            ->andWhere('p.startsAt <= :endsAt')
            ->andWhere('p.endsAt >= :startsAt')
            ->setParameter('teamMember', $teamMember)
            ->setParameter('startsAt', $startsAt)
            ->setParameter('endsAt', $endsAt);

        if (null !== $excluding) {
            $qb->andWhere('p.id != :excludingId')->setParameter('excludingId', $excluding->getId());
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Periods of $teamMember whose [startsAt, endsAt] intersects
     * [$from, $to] — what PlanningSnapshotService copies into
     * PlanningSnapshotNonParticipationPeriod rows
     * (docs/planning-generation.md §Non-participation administrative).
     *
     * @return list<TeamMemberNonParticipationPeriod>
     */
    public function findIntersecting(PlanningTeamMember $teamMember, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.teamMember = :teamMember')
            ->andWhere('p.startsAt < :to')
            ->andWhere('p.endsAt > :from')
            ->setParameter('teamMember', $teamMember)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('p.startsAt', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
