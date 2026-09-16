<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\DutyType;
use App\Entity\Team;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DutyType>
 */
class DutyTypeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DutyType::class);
    }

    public function findOneByTeamAndCode(Team $team, string $code): ?DutyType
    {
        return $this->findOneBy(['team' => $team, 'code' => $code]);
    }
}
