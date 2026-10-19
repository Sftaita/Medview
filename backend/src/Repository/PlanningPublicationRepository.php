<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Planning;
use App\Entity\PlanningCycle;
use App\Entity\PlanningPublication;
use App\Entity\PlanningPublicationKind;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

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

    /** docs/decisions.md D196: the reference of a period's "Modifications non publiées" — never another period's. */
    public function findLatestForCycle(PlanningCycle $cycle): ?PlanningPublication
    {
        return $this->findOneBy(['cycle' => $cycle], ['publishedAt' => 'DESC', 'id' => 'DESC']);
    }

    public function findFirstForCycle(PlanningCycle $cycle): ?PlanningPublication
    {
        return $this->findOneBy(['cycle' => $cycle, 'kind' => PlanningPublicationKind::FIRST]);
    }

    /**
     * @return list<PlanningPublication> oldest first
     */
    public function findByCycle(PlanningCycle $cycle): array
    {
        return $this->findBy(['cycle' => $cycle], ['publishedAt' => 'ASC', 'id' => 'ASC']);
    }

    public function findOneByStableId(string $stableId): ?PlanningPublication
    {
        return Uuid::isValid($stableId) ? $this->findOneBy(['stableId' => Uuid::fromString($stableId)]) : null;
    }
}
