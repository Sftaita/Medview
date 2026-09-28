<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PlanningSnapshotDemandTrigger;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PlanningSnapshotDemandTrigger>
 */
class PlanningSnapshotDemandTriggerRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PlanningSnapshotDemandTrigger::class);
    }
}
