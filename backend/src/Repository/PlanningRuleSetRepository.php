<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PlanningRuleSet;
use App\Entity\PlanningRuleSetStatus;
use App\Entity\Team;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PlanningRuleSet>
 */
class PlanningRuleSetRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PlanningRuleSet::class);
    }

    public function findActive(Team $team): ?PlanningRuleSet
    {
        return $this->findOneBy(['team' => $team, 'status' => PlanningRuleSetStatus::ACTIVE]);
    }

    public function findNextVersionNumber(Team $team): int
    {
        $maxVersion = $this->createQueryBuilder('r')
            ->select('MAX(r.version)')
            ->andWhere('r.team = :team')
            ->setParameter('team', $team)
            ->getQuery()
            ->getSingleScalarResult();

        return null === $maxVersion ? 1 : ((int) $maxVersion + 1);
    }
}
