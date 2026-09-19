<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Planning;
use App\Entity\PlanningLine;
use App\Entity\PlanningPeriod;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<PlanningLine>
 */
class PlanningLineRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PlanningLine::class);
    }

    public function findOneByStableId(string $stableId): ?PlanningLine
    {
        try {
            $uuid = Uuid::fromString($stableId);
        } catch (\InvalidArgumentException) {
            return null;
        }

        return $this->findOneBy(['stableId' => $uuid]);
    }

    /**
     * @return list<PlanningLine>
     */
    public function findByPlanning(Planning $planning): array
    {
        return $this->findBy(['planning' => $planning], ['position' => 'ASC']);
    }

    public function countByPlanning(Planning $planning): int
    {
        return (int) $this->count(['planning' => $planning]);
    }

    /**
     * The at-most-one PlanningLine driving a given PlanningPeriod — v1's
     * strict 1:1 PlanningTeam↔PlanningLine relationship (docs/planning.md
     * §4) makes this a plain unique lookup, never ambiguous.
     */
    public function findOneByPlanningPeriod(PlanningPeriod $planningPeriod): ?PlanningLine
    {
        return $this->findOneBy(['planningPeriod' => $planningPeriod]);
    }
}
