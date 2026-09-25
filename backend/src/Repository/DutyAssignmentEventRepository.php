<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Duty;
use App\Entity\DutyAssignmentEvent;
use App\Entity\Planning;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DutyAssignmentEvent>
 */
class DutyAssignmentEventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DutyAssignmentEvent::class);
    }

    /**
     * @return list<DutyAssignmentEvent>
     */
    public function findByDuty(Duty $duty): array
    {
        return $this->findBy(['duty' => $duty], ['occurredAt' => 'ASC']);
    }

    /**
     * @return list<DutyAssignmentEvent>
     */
    public function findByPlanning(Planning $planning): array
    {
        return $this->findBy(['planning' => $planning], ['occurredAt' => 'ASC']);
    }
}
