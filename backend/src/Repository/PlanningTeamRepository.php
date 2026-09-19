<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Planning;
use App\Entity\PlanningTeam;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<PlanningTeam>
 */
class PlanningTeamRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PlanningTeam::class);
    }

    public function findOneByStableId(string $stableId): ?PlanningTeam
    {
        try {
            $uuid = Uuid::fromString($stableId);
        } catch (\InvalidArgumentException) {
            return null;
        }

        return $this->findOneBy(['stableId' => $uuid]);
    }

    /**
     * @return list<PlanningTeam>
     */
    public function findByPlanning(Planning $planning): array
    {
        return $this->findBy(['planning' => $planning]);
    }
}
