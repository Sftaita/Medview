<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AllocationFamily;
use App\Entity\PlanningTeam;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AllocationFamily>
 */
class AllocationFamilyRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AllocationFamily::class);
    }

    public function findOneByTeamAndCode(PlanningTeam $team, string $code): ?AllocationFamily
    {
        return $this->findOneBy(['team' => $team, 'code' => $code]);
    }

    /**
     * @return list<AllocationFamily>
     */
    public function findActiveByTeam(PlanningTeam $team): array
    {
        return $this->findBy(['team' => $team, 'active' => true]);
    }
}
