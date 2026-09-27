<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Planning;
use App\Entity\PlanningJob;
use App\Entity\PlanningJobStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PlanningJob>
 */
class PlanningJobRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PlanningJob::class);
    }

    /** The job the calendar screen shows: the active one if any, otherwise the most recent one. */
    public function findLatestForPlanning(Planning $planning): ?PlanningJob
    {
        return $this->findOneBy(['planning' => $planning], ['createdAt' => 'DESC', 'id' => 'DESC']);
    }

    public function findActiveForPlanning(Planning $planning): ?PlanningJob
    {
        return $this->createQueryBuilder('j')
            ->andWhere('j.planning = :planning')
            ->andWhere('j.status IN (:active)')
            ->setParameter('planning', $planning)
            ->setParameter('active', [PlanningJobStatus::QUEUED->value, PlanningJobStatus::RUNNING->value])
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
