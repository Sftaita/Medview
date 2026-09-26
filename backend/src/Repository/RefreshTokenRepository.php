<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\RefreshToken;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RefreshToken>
 */
class RefreshTokenRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RefreshToken::class);
    }

    public function findOneByTokenHash(string $tokenHash): ?RefreshToken
    {
        return $this->findOneBy(['tokenHash' => $tokenHash]);
    }

    /**
     * Revokes every not-yet-revoked token in a family — used both for
     * logout and for the defensive family-wide revocation triggered by
     * reuse detection / a disabled account. Deliberately loads and mutates
     * entities one by one rather than issuing a bulk DQL UPDATE: a bulk
     * query would update the database directly without refreshing any
     * already-hydrated RefreshToken object's $revokedAt, silently leaving
     * stale in-memory state for any caller (starting with the token that
     * triggered this very call) that still holds a reference to one of
     * these rows. The caller is expected to flush().
     */
    public function revokeFamily(string $familyId): void
    {
        $tokens = $this->createQueryBuilder('t')
            ->where('t.familyId = :familyId')
            ->andWhere('t.revokedAt IS NULL')
            ->setParameter('familyId', $familyId)
            ->getQuery()
            ->getResult();

        foreach ($tokens as $token) {
            $token->revoke();
        }
    }

    /**
     * Revokes every not-yet-revoked token belonging to $user, across every
     * family — unlike revokeFamily(), which only ever kills the single
     * lineage a given raw token belongs to. Used after a sensitive
     * credentials change (password reset) where *all* of a user's sessions
     * must end, not just the one that triggered it. Same one-by-one
     * rationale as revokeFamily(): a bulk UPDATE would never refresh any
     * already-hydrated RefreshToken still held in memory by the caller.
     */
    public function revokeAllForUser(User $user): void
    {
        $tokens = $this->createQueryBuilder('t')
            ->where('t.user = :user')
            ->andWhere('t.revokedAt IS NULL')
            ->setParameter('user', $user)
            ->getQuery()
            ->getResult();

        foreach ($tokens as $token) {
            $token->revoke();
        }
    }
}
