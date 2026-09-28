<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PlanningSnapshotExternalCommitment;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PlanningSnapshotExternalCommitment>
 */
class PlanningSnapshotExternalCommitmentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PlanningSnapshotExternalCommitment::class);
    }
}
