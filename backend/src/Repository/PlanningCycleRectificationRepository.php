<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PlanningCycle;
use App\Entity\PlanningCycleRectification;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PlanningCycleRectification>
 */
class PlanningCycleRectificationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PlanningCycleRectification::class);
    }

    /**
     * @return list<PlanningCycleRectification> oldest first
     */
    public function findByCycle(PlanningCycle $cycle): array
    {
        return $this->findBy(['cycle' => $cycle], ['id' => 'ASC']);
    }
}
