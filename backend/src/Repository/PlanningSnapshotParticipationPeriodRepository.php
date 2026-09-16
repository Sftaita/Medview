<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PlanningSnapshotParticipationPeriod;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PlanningSnapshotParticipationPeriod>
 */
class PlanningSnapshotParticipationPeriodRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PlanningSnapshotParticipationPeriod::class);
    }
}
