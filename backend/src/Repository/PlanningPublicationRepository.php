<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Planning;
use App\Entity\PlanningPublication;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PlanningPublication>
 */
class PlanningPublicationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PlanningPublication::class);
    }

    /** The reference "Modifications non publiées" is computed against (docs/decisions.md D143). */
    public function findLatestForPlanning(Planning $planning): ?PlanningPublication
    {
        return $this->findOneBy(['planning' => $planning], ['publishedAt' => 'DESC', 'id' => 'DESC']);
    }

    public function findFirstForPlanning(Planning $planning): ?PlanningPublication
    {
        return $this->findOneBy(['planning' => $planning], ['publishedAt' => 'ASC', 'id' => 'ASC']);
    }

    /**
     * @return list<PlanningPublication> oldest first
     */
    public function findByPlanning(Planning $planning): array
    {
        return $this->findBy(['planning' => $planning], ['publishedAt' => 'ASC', 'id' => 'ASC']);
    }
}
