<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PlanningPublicationNotificationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * The email one person must receive for one PlanningPublication
 * (docs/decisions.md D172) — an outbox row, written in the same
 * transaction as the publication itself, so a diffusion can never exist
 * without the notifications it owes.
 *
 * `$changes` freezes what a republication email says to this person
 * (their own changes only, computed from the same locked calendar as the
 * publication's entries): a retry hours later sends exactly the same
 * content, and the attached PDF is rendered from the same publication's
 * frozen entries. Null for a first publication (no changes, the PDF only).
 *
 * Unlike PlanningPublication/Entry this row is mutable — it is a delivery
 * state, not a historical fact: PENDING → SENDING (claimed by exactly one
 * sender, PlanningPublicationNotificationRepository::claim) → SENT or
 * FAILED (retried by `app:publication-notifications:retry` until
 * MAX_ATTEMPTS). A SENT row is never sent again; one row per
 * (publication, user).
 */
#[ORM\Entity(repositoryClass: PlanningPublicationNotificationRepository::class)]
#[ORM\Table(name: 'planning_publication_notifications')]
#[ORM\UniqueConstraint(name: 'uniq_planning_publication_notifications_user', columns: ['publication_id', 'user_id'])]
#[ORM\Index(columns: ['status'], name: 'idx_planning_publication_notifications_status')]
class PlanningPublicationNotification
{
    public const MAX_ATTEMPTS = 5;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: PlanningPublication::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private PlanningPublication $publication;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private User $user;

    /**
     * @var list<array{kind: string, line: string, dutyType: ?string, block: ?string, dates: list<string>}>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $changes;

    #[ORM\Column(length: 10, enumType: PublicationNotificationStatus::class)]
    private PublicationNotificationStatus $status = PublicationNotificationStatus::PENDING;

    #[ORM\Column]
    private int $attempts = 0;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $claimedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $sentAt = null;

    /**
     * @param list<array{kind: string, line: string, dutyType: ?string, block: ?string, dates: list<string>}>|null $changes
     */
    public function __construct(PlanningPublication $publication, User $user, ?array $changes, \DateTimeImmutable $createdAt)
    {
        $this->publication = $publication;
        $this->user = $user;
        $this->changes = $changes;
        $this->createdAt = $createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPublication(): PlanningPublication
    {
        return $this->publication;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    /**
     * @return list<array{kind: string, line: string, dutyType: ?string, block: ?string, dates: list<string>}>|null
     */
    public function getChanges(): ?array
    {
        return $this->changes;
    }

    public function getStatus(): PublicationNotificationStatus
    {
        return $this->status;
    }

    public function getAttempts(): int
    {
        return $this->attempts;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getClaimedAt(): ?\DateTimeImmutable
    {
        return $this->claimedAt;
    }

    public function getSentAt(): ?\DateTimeImmutable
    {
        return $this->sentAt;
    }

    /** Only after a successful claim (SENDING). */
    public function markSent(\DateTimeImmutable $at): void
    {
        $this->status = PublicationNotificationStatus::SENT;
        $this->sentAt = $at;
    }

    /** Only after a successful claim (SENDING). */
    public function markFailed(): void
    {
        $this->status = PublicationNotificationStatus::FAILED;
    }

    /** Only after a successful claim (SENDING): the account was deactivated meanwhile. */
    public function cancel(): void
    {
        $this->status = PublicationNotificationStatus::CANCELLED;
    }
}
