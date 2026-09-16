<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PlanningSnapshotAvailabilityPeriod;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PlanningSnapshotAvailabilityPeriod>
 */
class PlanningSnapshotAvailabilityPeriodRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PlanningSnapshotAvailabilityPeriod::class);
    }
}
