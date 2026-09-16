<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\RefreshTokenRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * One row per issued refresh token (never the raw value — only its SHA-256
 * hash). Rows form a chain via $familyId: every rotation of a given login
 * session creates a new row with the same familyId and points the old row's
 * $replacedByTokenId at it. Revoking a familyId kills the whole session
 * lineage — used both for logout and for reacting to reuse of an
 * already-rotated-out token. See docs/authentication.md.
 */
#[ORM\Entity(repositoryClass: RefreshTokenRepository::class)]
#[ORM\Table(name: 'refresh_tokens')]
#[ORM\Index(columns: ['family_id'], name: 'idx_refresh_tokens_family_id')]
class RefreshToken
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 64, unique: true)]
    private string $tokenHash;

    /**
     * Groups every token issued across the rotations of a single login
     * session. A fresh login starts a new family; refreshing keeps it.
     */
    #[ORM\Column(length: 64)]
    private string $familyId;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $revokedAt = null;

    /**
     * Set once this token has been consumed by a rotation. A non-null value
     * here (regardless of $revokedAt) means "already used" — presenting the
     * raw token behind this row again is a reuse/replay attempt.
     */
    #[ORM\Column(nullable: true)]
    private ?int $replacedByTokenId = null;

    #[ORM\Column(length: 45, nullable: true)]
    private ?string $createdByIp = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $userAgent = null;

    public function __construct(
        User $user,
        string $tokenHash,
        string $familyId,
        \DateTimeImmutable $expiresAt,
        ?string $createdByIp,
        ?string $userAgent,
    ) {
        $this->user = $user;
        $this->tokenHash = $tokenHash;
        $this->familyId = $familyId;
        $this->createdAt = new \DateTimeImmutable();
        $this->expiresAt = $expiresAt;
        $this->createdByIp = $createdByIp;
        $this->userAgent = null === $userAgent ? null : mb_substr($userAgent, 0, 255);
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

    public function getFamilyId(): string
    {
        return $this->familyId;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function isExpired(): bool
    {
        return $this->expiresAt < new \DateTimeImmutable();
    }

    public function getRevokedAt(): ?\DateTimeImmutable
    {
        return $this->revokedAt;
    }

    public function isRevoked(): bool
    {
        return null !== $this->revokedAt;
    }

    public function revoke(): void
    {
        $this->revokedAt ??= new \DateTimeImmutable();
    }

    public function getReplacedByTokenId(): ?int
    {
        return $this->replacedByTokenId;
    }

    public function isReplaced(): bool
    {
        return null !== $this->replacedByTokenId;
    }

    /**
     * Marks this token as consumed by rotation: it becomes both replaced
     * and revoked, so presenting its raw value again is rejected either way.
     */
    public function markReplacedBy(self $newToken): void
    {
        $this->replacedByTokenId = $newToken->getId();
        $this->revoke();
    }
}
