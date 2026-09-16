<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\DutyAssignment;
use App\Entity\PlanningGeneration;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<DutyAssignment>
 */
class DutyAssignmentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DutyAssignment::class);
    }

    public function findOneByStableId(string $stableId): ?DutyAssignment
    {
        try {
            $uuid = Uuid::fromString($stableId);
        } catch (\InvalidArgumentException) {
            return null;
        }

        return $this->findOneBy(['stableId' => $uuid]);
    }

    /**
     * @return list<DutyAssignment>
     */
    public function findByGeneration(PlanningGeneration $generation): array
    {
        return $this->findBy(['generation' => $generation]);
    }
}
