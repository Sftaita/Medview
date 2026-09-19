<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\FairnessPeriod;
use App\Entity\PlanningTeam;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<FairnessPeriod>
 */
class FairnessPeriodRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FairnessPeriod::class);
    }

    /**
     * Any existing FairnessPeriod of this Team whose [startsAt, endsAt)
     * range intersects the given one — used by FairnessPeriodService to
     * reject overlaps with a friendly error before the database's own
     * exclusion constraint would (docs/planning-domain.md).
     *
     * @return list<FairnessPeriod>
     */
    public function findOverlapping(PlanningTeam $team, \DateTimeImmutable $startsAt, \DateTimeImmutable $endsAt): array
    {
        return $this->createQueryBuilder('f')
            ->andWhere('f.team = :team')
            ->andWhere('f.startsAt < :endsAt')
            ->andWhere('f.endsAt > :startsAt')
            ->setParameter('team', $team)
            ->setParameter('startsAt', $startsAt)
            ->setParameter('endsAt', $endsAt)
            ->getQuery()
            ->getResult();
    }
}
