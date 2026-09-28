<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PlanningSnapshotDemandPolicy;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PlanningSnapshotDemandPolicy>
 */
class PlanningSnapshotDemandPolicyRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PlanningSnapshotDemandPolicy::class);
    }
}
