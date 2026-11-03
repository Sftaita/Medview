<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PeriodDateChange;
use App\Entity\PlanningCycle;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PeriodDateChange>
 */
class PeriodDateChangeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PeriodDateChange::class);
    }

    /**
     * @return list<PeriodDateChange> oldest first
     */
    public function findByCycle(PlanningCycle $cycle): array
    {
        return $this->findBy(['cycle' => $cycle], ['id' => 'ASC']);
    }
}
