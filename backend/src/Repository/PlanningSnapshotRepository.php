<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PlanningGeneration;
use App\Entity\PlanningSnapshot;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PlanningSnapshot>
 */
class PlanningSnapshotRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PlanningSnapshot::class);
    }

    public function findOneByGeneration(PlanningGeneration $generation): ?PlanningSnapshot
    {
        return $this->findOneBy(['generation' => $generation]);
    }
}
