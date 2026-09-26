<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PasswordResetToken;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PasswordResetToken>
 */
class PasswordResetTokenRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PasswordResetToken::class);
    }

    /**
     * Row-locked lookup (SELECT … FOR UPDATE) — must run inside a
     * transaction. Two simultaneous confirmations of the same raw token
     * serialize on this row: the second one only proceeds once the first
     * has committed, and then sees a non-usable row (same pattern as
     * TeamInvitationRepository::findOneByTokenHashForUpdate()).
     */
    public function findOneByTokenHashForUpdate(string $tokenHash): ?PasswordResetToken
    {
        return $this->createQueryBuilder('t')
            ->andWhere('t.tokenHash = :hash')
            ->setParameter('hash', $tokenHash)
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getOneOrNullResult();
    }

    /**
     * Every still-usable (not consumed, not revoked, not expired) token for
     * $user, locked for update — must run inside a transaction. Used before
     * issuing a new token so a newer request always supersedes older ones
     * even under concurrent requests, and before a successful reset so no
     * other outstanding token survives it.
     *
     * @return list<PasswordResetToken>
     */
    public function findUsableByUserForUpdate(User $user, \DateTimeImmutable $now): array
    {
        /** @var list<PasswordResetToken> $result */
        $result = $this->createQueryBuilder('t')
            ->andWhere('t.user = :user')
            ->andWhere('t.consumedAt IS NULL')
            ->andWhere('t.revokedAt IS NULL')
            ->andWhere('t.expiresAt > :now')
            ->setParameter('user', $user)
            ->setParameter('now', $now)
            ->orderBy('t.createdAt', 'ASC')
            ->addOrderBy('t.id', 'ASC')
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getResult();

        return $result;
    }
}
