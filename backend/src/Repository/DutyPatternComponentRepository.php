<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\DutyPatternComponent;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DutyPatternComponent>
 */
class DutyPatternComponentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DutyPatternComponent::class);
    }
}
