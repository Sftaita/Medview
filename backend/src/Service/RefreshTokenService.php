<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\RefreshToken;
use App\Entity\User;
use App\Exception\InvalidRefreshTokenException;
use App\Repository\RefreshTokenRepository;
use App\Security\RefreshTokenFailureReason;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Exception\DisabledException;

/**
 * Issues, rotates and revokes refresh tokens. Never touches HTTP concerns
 * (cookies, responses) — that stays in the controllers/handlers, this only
 * knows about raw token strings and the database. See
 * docs/authentication.md for the rotation/reuse-detection design.
 */
final class RefreshTokenService
{
    public function __construct(
        private readonly RefreshTokenRepository $repository,
        private readonly EntityManagerInterface $entityManager,
        private readonly int $ttlSeconds,
    ) {
    }

    /**
     * Issues a brand new token family — used at login.
     *
     * @return string the raw token to hand to the client (never persisted as-is)
     */
    public function issueNewFamily(User $user, Request $request): string
    {
        return $this->issue($user, $this->generateFamilyId(), $request);
    }

    /**
     * Validates and rotates a refresh token: the presented one is
     * invalidated and a new one is issued in the same family.
     *
     * @return array{0: User, 1: string} the owning user and the new raw token
     *
     * @throws InvalidRefreshTokenException
     * @throws DisabledException
     */
    public function rotate(string $rawToken, Request $request): array
    {
        $current = $this->repository->findOneByTokenHash($this->hash($rawToken));

        if (!$current) {
            throw new InvalidRefreshTokenException(RefreshTokenFailureReason::NOT_FOUND);
        }

        if ($current->isRevoked()) {
            // Defensive: whether this is a logout-revoked token or one
            // that's already been rotated away, presenting it again means
            // the whole session lineage may be compromised.
            $this->repository->revokeFamily($current->getFamilyId());
            $this->entityManager->flush();

            throw new InvalidRefreshTokenException($current->isReplaced() ? RefreshTokenFailureReason::REUSED : RefreshTokenFailureReason::REVOKED);
        }

        if ($current->isExpired()) {
            throw new InvalidRefreshTokenException(RefreshTokenFailureReason::EXPIRED);
        }

        $user = $current->getUser();
        if (!$user->isActive()) {
            $this->repository->revokeFamily($current->getFamilyId());
            $this->entityManager->flush();

            throw new InvalidRefreshTokenException(RefreshTokenFailureReason::ACCOUNT_DISABLED);
        }

        $newRawToken = $this->issue($user, $current->getFamilyId(), $request);
        $newToken = $this->repository->findOneByTokenHash($this->hash($newRawToken));
        \assert(null !== $newToken);
        $current->markReplacedBy($newToken);
        $this->entityManager->flush();

        return [$user, $newRawToken];
    }

    /**
     * Revokes the whole family behind a raw token, if it still exists.
     * Idempotent and silent on an unknown/already-invalid token — logout
     * always succeeds from the client's point of view.
     */
    public function revokeByRawToken(string $rawToken): void
    {
        $token = $this->repository->findOneByTokenHash($this->hash($rawToken));
        if ($token) {
            $this->repository->revokeFamily($token->getFamilyId());
            $this->entityManager->flush();
        }
    }

    private function issue(User $user, string $familyId, Request $request): string
    {
        $rawToken = bin2hex(random_bytes(32));

        $token = new RefreshToken(
            user: $user,
            tokenHash: $this->hash($rawToken),
            familyId: $familyId,
            expiresAt: new \DateTimeImmutable("+{$this->ttlSeconds} seconds"),
            createdByIp: $request->getClientIp(),
            userAgent: $request->headers->get('User-Agent'),
        );

        $this->entityManager->persist($token);
        $this->entityManager->flush();

        return $rawToken;
    }

    private function generateFamilyId(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * SHA-256, not the (slow, adaptive) password hasher: the raw token is
     * already 256 bits of randomness, not a low-entropy human secret, so a
     * fast, deterministic hash is the right tool — see docs/decisions.md.
     */
    private function hash(string $rawToken): string
    {
        return hash('sha256', $rawToken);
    }
}
