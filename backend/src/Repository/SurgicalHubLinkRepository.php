<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\SurgicalHubLink;
use App\Entity\SurgicalHubLinkStatus;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SurgicalHubLink>
 */
class SurgicalHubLinkRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SurgicalHubLink::class);
    }

    /** The ACTIVE association of $user — the only kind that is read. */
    public function findActiveForUser(User $user): ?SurgicalHubLink
    {
        $link = $this->findCurrentForUser($user);

        return null !== $link && $link->isActive() ? $link : null;
    }

    /** The association of $user that is not ended: ACTIVE or SUSPENDED (at most one, partial unique index). */
    public function findCurrentForUser(User $user): ?SurgicalHubLink
    {
        return $this->createQueryBuilder('l')
            ->andWhere('l.user = :user')
            ->andWhere('l.status IN (:current)')
            ->setParameter('user', $user)
            ->setParameter('current', [SurgicalHubLinkStatus::ACTIVE, SurgicalHubLinkStatus::SUSPENDED])
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findCurrentForSurgicalHubUser(string $surgicalHubUserId): ?SurgicalHubLink
    {
        return $this->createQueryBuilder('l')
            ->andWhere('l.surgicalHubUserId = :id')
            ->andWhere('l.status IN (:current)')
            ->setParameter('id', $surgicalHubUserId)
            ->setParameter('current', [SurgicalHubLinkStatus::ACTIVE, SurgicalHubLinkStatus::SUSPENDED])
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Every ACTIVE association, never-synchronised ones first, then the
     * longest without a success — the order of the periodic synchronisation.
     *
     * @return list<SurgicalHubLink>
     */
    public function findActiveForSync(): array
    {
        return $this->createQueryBuilder('l')
            ->addSelect('CASE WHEN l.lastSuccessfulSyncAt IS NULL THEN 0 ELSE 1 END AS HIDDEN synced')
            ->andWhere('l.status = :active')
            ->setParameter('active', SurgicalHubLinkStatus::ACTIVE)
            ->orderBy('synced', 'ASC')
            ->addOrderBy('l.lastSuccessfulSyncAt', 'ASC')
            ->addOrderBy('l.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function countSuspended(): int
    {
        return (int) $this->createQueryBuilder('l')
            ->select('COUNT(l.id)')
            ->andWhere('l.status = :suspended')
            ->setParameter('suspended', SurgicalHubLinkStatus::SUSPENDED)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * The most recent association of $user, whatever its status — what the
     * owner's settings show ("dissociée par SurgicalHub le …").
     */
    public function findLatestForUser(User $user): ?SurgicalHubLink
    {
        return $this->createQueryBuilder('l')
            ->andWhere('l.user = :user')
            ->setParameter('user', $user)
            ->orderBy('l.linkedAt', 'DESC')
            ->addOrderBy('l.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Row-locked re-read (SELECT … FOR UPDATE), inside a transaction: two
     * operations on the same association (a sync and a revocation, two
     * syncs) serialize on this row and the second one sees the first's result.
     */
    public function lock(SurgicalHubLink $link): SurgicalHubLink
    {
        $this->getEntityManager()->refresh($link, LockMode::PESSIMISTIC_WRITE);

        return $link;
    }
}
