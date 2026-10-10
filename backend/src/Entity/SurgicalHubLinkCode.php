<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\SurgicalHubLinkCodeRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A short-lived, single-use association code (docs/surgicalhub-integration.md
 * §4.2). The person generates it in MedVue and types it into SurgicalHub;
 * proving they own the MedVue account is its only purpose. Same storage rules
 * as PasswordResetToken: only the SHA-256 of the normalized code is stored,
 * the raw value is shown once and never persisted or logged.
 *
 * Unusable once consumed (an association was made with it), revoked (a newer
 * code was generated for the same account) or expired (checked live).
 */
#[ORM\Entity(repositoryClass: SurgicalHubLinkCodeRepository::class)]
#[ORM\Table(name: 'surgical_hub_link_codes')]
#[ORM\UniqueConstraint(name: 'uniq_surgical_hub_link_codes_code_hash', columns: ['code_hash'])]
#[ORM\Index(columns: ['user_id'], name: 'idx_surgical_hub_link_codes_user_id')]
class SurgicalHubLinkCode
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    /** SHA-256 (hex) of the normalized code. The raw value is never persisted. */
    #[ORM\Column(length: 64)]
    private string $codeHash;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $consumedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $revokedAt = null;

    public function __construct(User $user, string $codeHash, \DateTimeImmutable $now, \DateTimeImmutable $expiresAt)
    {
        $this->user = $user;
        $this->codeHash = $codeHash;
        $this->createdAt = $now;
        $this->expiresAt = $expiresAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getCodeHash(): string
    {
        return $this->codeHash;
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

    public function consume(\DateTimeImmutable $now): void
    {
        $this->consumedAt ??= $now;
    }

    public function revoke(\DateTimeImmutable $now): void
    {
        $this->revokedAt ??= $now;
    }
}
