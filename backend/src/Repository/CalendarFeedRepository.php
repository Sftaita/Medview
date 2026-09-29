<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CalendarFeed;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CalendarFeed>
 */
class CalendarFeedRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CalendarFeed::class);
    }

    public function findActiveOf(User $user): ?CalendarFeed
    {
        return $this->createQueryBuilder('f')
            ->andWhere('f.user = :user')
            ->andWhere('f.revokedAt IS NULL')
            ->setParameter('user', $user)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * The active feed behind $token, or null — unknown and revoked tokens are
     * never told apart. Anything that is not a well-formed token never
     * reaches the database.
     */
    public function findActiveByToken(string $token): ?CalendarFeed
    {
        if (1 !== preg_match('/^[0-9a-f]{64}$/', $token)) {
            return null;
        }

        return $this->createQueryBuilder('f')
            ->andWhere('f.token = :token')
            ->andWhere('f.revokedAt IS NULL')
            ->setParameter('token', $token)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
