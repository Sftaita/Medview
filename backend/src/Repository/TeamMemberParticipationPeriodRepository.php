<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PlanningTeamMember;
use App\Entity\TeamMemberParticipationPeriod;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TeamMemberParticipationPeriod>
 */
class TeamMemberParticipationPeriodRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TeamMemberParticipationPeriod::class);
    }

    public function findOpenPeriod(PlanningTeamMember $teamMember): ?TeamMemberParticipationPeriod
    {
        return $this->findOneBy([
            'teamMember' => $teamMember,
            'validTo' => null,
        ]);
    }

    /**
     * The segment in force at $date, if any — the sole data source behind
     * participationFactorAt() (docs/allocation-algorithm.md §20).
     */
    public function findEffectiveAt(PlanningTeamMember $teamMember, \DateTimeImmutable $date): ?TeamMemberParticipationPeriod
    {
        $qb = $this->createQueryBuilder('p')
            ->andWhere('p.teamMember = :teamMember')
            ->andWhere('p.validFrom <= :date')
            ->andWhere('p.validTo IS NULL OR p.validTo > :date')
            ->setParameter('teamMember', $teamMember)
            ->setParameter('date', $date)
            ->orderBy('p.validFrom', 'DESC')
            ->setMaxResults(1);

        /* @var TeamMemberParticipationPeriod|null */
        return $qb->getQuery()->getOneOrNullResult();
    }

    /**
     * Segments of $teamMember intersecting [$from, $to) — what
     * PlanningSnapshotService copies verbatim into
     * PlanningSnapshotParticipationPeriod rows (docs/planning-generation.md
     * §Participation).
     *
     * @return list<TeamMemberParticipationPeriod>
     */
    public function findIntersecting(PlanningTeamMember $teamMember, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.teamMember = :teamMember')
            ->andWhere('p.validFrom < :to')
            ->andWhere('p.validTo IS NULL OR p.validTo > :from')
            ->setParameter('teamMember', $teamMember)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('p.validFrom', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
