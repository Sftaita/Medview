<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PlatformAuditEvent;
use App\Entity\PlatformAuditEventType;
use App\Entity\PlatformAuditOutcome;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PlatformAuditEvent>
 */
class PlatformAuditEventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PlatformAuditEvent::class);
    }

    /**
     * Newest first, actor and target fetched in the same query (no N+1).
     *
     * @return list<PlatformAuditEvent>
     */
    public function findPage(?PlatformAuditEventType $type, ?PlatformAuditOutcome $outcome, ?User $target, int $offset, int $limit): array
    {
        /* @var list<PlatformAuditEvent> */
        return $this->filtered($type, $outcome, $target)
            ->addSelect('actor', 'target')
            ->leftJoin('e.actor', 'actor')
            ->leftJoin('e.targetUser', 'target')
            ->orderBy('e.occurredAt', 'DESC')
            ->addOrderBy('e.id', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function countFiltered(?PlatformAuditEventType $type, ?PlatformAuditOutcome $outcome, ?User $target): int
    {
        return (int) $this->filtered($type, $outcome, $target)
            ->select('COUNT(e.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    private function filtered(?PlatformAuditEventType $type, ?PlatformAuditOutcome $outcome, ?User $target): QueryBuilder
    {
        $qb = $this->createQueryBuilder('e');
        if (null !== $type) {
            $qb->andWhere('e.type = :type')->setParameter('type', $type);
        }
        if (null !== $outcome) {
            $qb->andWhere('e.outcome = :outcome')->setParameter('outcome', $outcome);
        }
        if (null !== $target) {
            $qb->andWhere('e.targetUser = :target')->setParameter('target', $target);
        }

        return $qb;
    }
}
