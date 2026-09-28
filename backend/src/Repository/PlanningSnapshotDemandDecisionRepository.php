<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PlanningSnapshotDemandDecision;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PlanningSnapshotDemandDecision>
 */
class PlanningSnapshotDemandDecisionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PlanningSnapshotDemandDecision::class);
    }
}
