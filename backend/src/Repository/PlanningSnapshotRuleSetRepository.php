<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PlanningSnapshot;
use App\Entity\PlanningSnapshotRuleSet;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PlanningSnapshotRuleSet>
 */
class PlanningSnapshotRuleSetRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PlanningSnapshotRuleSet::class);
    }

    public function findOneBySnapshot(PlanningSnapshot $snapshot): ?PlanningSnapshotRuleSet
    {
        return $this->findOneBy(['snapshot' => $snapshot]);
    }
}
