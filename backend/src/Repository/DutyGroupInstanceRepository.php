<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\DutyGroupInstance;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DutyGroupInstance>
 */
class DutyGroupInstanceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DutyGroupInstance::class);
    }
}
