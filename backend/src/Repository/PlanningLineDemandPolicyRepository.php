<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\DemandPolicyStatus;
use App\Entity\PlanningLine;
use App\Entity\PlanningLineDemandPolicy;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PlanningLineDemandPolicy>
 */
class PlanningLineDemandPolicyRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PlanningLineDemandPolicy::class);
    }

    /** The policy in force for this line, or null — which means INDEPENDENT (docs/decisions.md D162). */
    public function findActiveForLine(PlanningLine $line): ?PlanningLineDemandPolicy
    {
        return $this->findOneBy(['targetLine' => $line, 'status' => DemandPolicyStatus::ACTIVE]);
    }

    public function findNextVersionNumber(PlanningLine $line): int
    {
        return 1 + (int) $this->createQueryBuilder('p')
            ->select('MAX(p.version)')
            ->andWhere('p.targetLine = :line')
            ->setParameter('line', $line)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * The policies in force that use $line as their source.
     *
     * @return list<PlanningLineDemandPolicy>
     */
    public function findActiveUsingSource(PlanningLine $line): array
    {
        return $this->findBy(['sourceLine' => $line, 'status' => DemandPolicyStatus::ACTIVE], ['id' => 'ASC']);
    }

    /**
     * Every version of this line's policy, oldest first.
     *
     * @return list<PlanningLineDemandPolicy>
     */
    public function findHistoryForLine(PlanningLine $line): array
    {
        return $this->findBy(['targetLine' => $line], ['version' => 'ASC']);
    }
}
