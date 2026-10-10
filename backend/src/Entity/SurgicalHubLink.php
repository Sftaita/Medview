<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\SurgicalHubLinkRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * The association between one MedVue User and one SurgicalHub account
 * (docs/surgicalhub-integration.md §4, docs/decisions.md D182). It only ever
 * authorizes MedVue to *read* that person's leave from SurgicalHub — nothing
 * here is ever sent the other way.
 *
 * $stableId is the `linkId` both applications know: SurgicalHub stores it
 * against its own user after the code exchange, and MedVue reads leave
 * through it. $surgicalHubUserId is SurgicalHub's own identifier, kept only
 * to refuse a second MedVue account for the same SurgicalHub account.
 *
 * At most one ACTIVE row per User and one per SurgicalHub account — enforced
 * by two partial unique indexes (migration Version20261010090000), on top of
 * SurgicalHubLinkService's own checks. A revoked row is kept as history.
 */
#[ORM\Entity(repositoryClass: SurgicalHubLinkRepository::class)]
#[ORM\Table(name: 'surgical_hub_links')]
#[ORM\UniqueConstraint(name: 'uniq_surgical_hub_links_stable_id', columns: ['stable_id'])]
#[ORM\Index(columns: ['user_id'], name: 'idx_surgical_hub_links_user_id')]
#[ORM\Index(columns: ['status'], name: 'idx_surgical_hub_links_status')]
class SurgicalHubLink
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: 'uuid')]
    private Uuid $stableId;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private User $user;

    #[ORM\Column(length: 64)]
    private string $surgicalHubUserId;

    /** As SurgicalHub named its account at link time — shown to the MedVue owner, never sent back. */
    #[ORM\Column(length: 200)]
    private string $surgicalHubDisplayName;

    #[ORM\Column(length: 20, enumType: SurgicalHubLinkStatus::class)]
    private SurgicalHubLinkStatus $status = SurgicalHubLinkStatus::ACTIVE;

    /** True when a SurgicalHub administrator, not the person themselves, entered the code. */
    #[ORM\Column]
    private bool $linkedByAdministrator;

    #[ORM\Column]
    private \DateTimeImmutable $linkedAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $revokedAt = null;

    /** When SurgicalHub last answered that it does not know this association (cleared when it resumes). */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $suspendedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastSyncAttemptAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastSuccessfulSyncAt = null;

    /** A short code (SurgicalHubSyncError), never a technical message: it is shown to the owner. */
    #[ORM\Column(length: 40, nullable: true)]
    private ?string $lastSyncError = null;

    public function __construct(User $user, string $surgicalHubUserId, string $surgicalHubDisplayName, bool $linkedByAdministrator, \DateTimeImmutable $now)
    {
        $this->stableId = Uuid::v7();
        $this->user = $user;
        $this->surgicalHubUserId = $surgicalHubUserId;
        $this->surgicalHubDisplayName = $surgicalHubDisplayName;
        $this->linkedByAdministrator = $linkedByAdministrator;
        $this->linkedAt = $now;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getStableId(): Uuid
    {
        return $this->stableId;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getSurgicalHubUserId(): string
    {
        return $this->surgicalHubUserId;
    }

    public function getSurgicalHubDisplayName(): string
    {
        return $this->surgicalHubDisplayName;
    }

    public function getStatus(): SurgicalHubLinkStatus
    {
        return $this->status;
    }

    public function isActive(): bool
    {
        return SurgicalHubLinkStatus::ACTIVE === $this->status;
    }

    public function isSuspended(): bool
    {
        return SurgicalHubLinkStatus::SUSPENDED === $this->status;
    }

    /** Not ended yet: ACTIVE or SUSPENDED — it still holds both accounts (one current association each). */
    public function isCurrent(): bool
    {
        return $this->isActive() || $this->isSuspended();
    }

    public function getSuspendedAt(): ?\DateTimeImmutable
    {
        return $this->suspendedAt;
    }

    /** SurgicalHub does not know it (404): stop reading, keep everything. */
    public function suspend(\DateTimeImmutable $now): void
    {
        if (!$this->isActive()) {
            return;
        }

        $this->status = SurgicalHubLinkStatus::SUSPENDED;
        $this->suspendedAt = $now;
    }

    /** The owner associated the same pair again: reading resumes, the imports were never touched. */
    public function resume(): void
    {
        if ($this->isSuspended()) {
            $this->status = SurgicalHubLinkStatus::ACTIVE;
        }
    }

    public function isLinkedByAdministrator(): bool
    {
        return $this->linkedByAdministrator;
    }

    public function getLinkedAt(): \DateTimeImmutable
    {
        return $this->linkedAt;
    }

    public function getRevokedAt(): ?\DateTimeImmutable
    {
        return $this->revokedAt;
    }

    public function getLastSyncAttemptAt(): ?\DateTimeImmutable
    {
        return $this->lastSyncAttemptAt;
    }

    public function getLastSuccessfulSyncAt(): ?\DateTimeImmutable
    {
        return $this->lastSuccessfulSyncAt;
    }

    public function getLastSyncError(): ?string
    {
        return $this->lastSyncError;
    }

    /** Terminal: a revoked association is never reactivated (a new one is a new row). */
    public function revoke(SurgicalHubLinkStatus $by, \DateTimeImmutable $now): void
    {
        if (\in_array($by, [SurgicalHubLinkStatus::ACTIVE, SurgicalHubLinkStatus::SUSPENDED], true)) {
            throw new \InvalidArgumentException('A revocation needs a revoked status.');
        }
        if (!$this->isCurrent()) {
            return;
        }

        $this->status = $by;
        $this->revokedAt = $now;
    }

    public function recordSyncSuccess(\DateTimeImmutable $now): void
    {
        $this->lastSyncAttemptAt = $now;
        $this->lastSuccessfulSyncAt = $now;
        $this->lastSyncError = null;
    }

    public function recordSyncFailure(string $errorCode, \DateTimeImmutable $now): void
    {
        $this->lastSyncAttemptAt = $now;
        $this->lastSyncError = $errorCode;
    }
}
