<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\SurgicalHubLinkCode;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SurgicalHubLinkCode>
 */
class SurgicalHubLinkCodeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SurgicalHubLinkCode::class);
    }

    /**
     * Row-locked lookup (SELECT … FOR UPDATE), inside a transaction: two
     * simultaneous redemptions of the same code serialize here, and the
     * second one sees it consumed (same pattern as
     * PasswordResetTokenRepository::findOneByTokenHashForUpdate()).
     */
    public function findOneByCodeHashForUpdate(string $codeHash): ?SurgicalHubLinkCode
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.codeHash = :hash')
            ->setParameter('hash', $codeHash)
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getOneOrNullResult();
    }

    /**
     * @return list<SurgicalHubLinkCode>
     */
    public function findPendingForUser(User $user): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.user = :user')
            ->andWhere('c.consumedAt IS NULL')
            ->andWhere('c.revokedAt IS NULL')
            ->setParameter('user', $user)
            ->getQuery()
            ->getResult();
    }
}
