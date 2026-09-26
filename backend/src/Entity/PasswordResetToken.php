<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PasswordResetTokenRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A single-use, expiring "forgot password" token (docs/decisions.md D013,
 * now implemented — see D141). Deliberately a separate entity, never
 * columns on User: the raw token exists only in the reset email's link,
 * only its SHA-256 hash is stored here, and nothing is ever deleted — the
 * history of reset attempts stays available (same reasoning as
 * RefreshToken, see docs/authentication.md).
 *
 * Three ways a row stops being usable, all distinct and all kept:
 *  - $consumedAt: a real password reset happened through it (terminal,
 *    the success path);
 *  - $revokedAt: superseded by a newer request for the same user before
 *    it was ever used (see PasswordResetService::requestReset());
 *  - expiry ($expiresAt in the past): never flipped persistently, checked
 *    live (isUsableAt()), same lazy pattern as TeamInvitation.
 */
#[ORM\Entity(repositoryClass: PasswordResetTokenRepository::class)]
#[ORM\Table(name: 'password_reset_tokens')]
#[ORM\UniqueConstraint(name: 'uniq_password_reset_tokens_token_hash', columns: ['token_hash'])]
#[ORM\Index(columns: ['user_id'], name: 'idx_password_reset_tokens_user_id')]
class PasswordResetToken
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    /** SHA-256 (hex) of the raw token. The raw value is never persisted. */
    #[ORM\Column(length: 64)]
    private string $tokenHash;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $consumedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $revokedAt = null;

    #[ORM\Column(length: 45, nullable: true)]
    private ?string $requestedByIp = null;

    public function __construct(
        User $user,
        string $tokenHash,
        \DateTimeImmutable $expiresAt,
        ?string $requestedByIp,
    ) {
        $this->user = $user;
        $this->tokenHash = $tokenHash;
        $this->createdAt = new \DateTimeImmutable();
        $this->expiresAt = $expiresAt;
        $this->requestedByIp = $requestedByIp;
    }

    public static function hashToken(string $rawToken): string
    {
        return hash('sha256', $rawToken);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getTokenHash(): string
    {
        return $this->tokenHash;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function getConsumedAt(): ?\DateTimeImmutable
    {
        return $this->consumedAt;
    }

    public function getRevokedAt(): ?\DateTimeImmutable
    {
        return $this->revokedAt;
    }

    public function isUsableAt(\DateTimeImmutable $now): bool
    {
        return null === $this->consumedAt
            && null === $this->revokedAt
            && $this->expiresAt > $now;
    }

    /** Terminal: a password reset actually happened through this token. */
    public function consume(): void
    {
        $this->consumedAt ??= new \DateTimeImmutable();
    }

    /** A newer request for the same user made this one obsolete before it was ever used. */
    public function revoke(): void
    {
        $this->revokedAt ??= new \DateTimeImmutable();
    }
}
