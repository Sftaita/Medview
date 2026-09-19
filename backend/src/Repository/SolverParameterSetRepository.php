<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\SolverParameterSet;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SolverParameterSet>
 */
class SolverParameterSetRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SolverParameterSet::class);
    }

    /**
     * The highest-versioned row — the one a new PlanningGeneration solve
     * resolves against (docs/decisions.md D106). `null` when the system has
     * never been seeded with one, a genuine precondition failure, never
     * silently defaulted.
     */
    public function findLatest(): ?SolverParameterSet
    {
        return $this->createQueryBuilder('s')
            ->orderBy('s.version', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
