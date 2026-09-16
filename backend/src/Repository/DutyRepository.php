<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Duty;
use App\Entity\PlanningPeriod;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<Duty>
 */
class DutyRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Duty::class);
    }

    public function findOneByStableId(string $stableId): ?Duty
    {
        try {
            $uuid = Uuid::fromString($stableId);
        } catch (\InvalidArgumentException) {
            return null;
        }

        return $this->findOneBy(['stableId' => $uuid]);
    }

    /**
     * @return list<Duty>
     */
    public function findByPlanningPeriod(PlanningPeriod $planningPeriod): array
    {
        return $this->findBy(['planningPeriod' => $planningPeriod], ['localDate' => 'ASC']);
    }
}
