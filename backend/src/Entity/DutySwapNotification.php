<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\DutySwapNotificationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One email the swap workflow owes one person (docs/duty-swaps.md §8) — an
 * outbox row written in the same transaction as the workflow step that
 * causes it, exactly like PlanningPublicationNotification (D172): a
 * confirmed swap can never exist without its two confirmation rows, and a
 * rolled-back swap never leaves one behind (so no confirmation is ever sent
 * for a swap that did not happen).
 *
 * $payload freezes what the email says (names, dates, line, planning) at
 * the moment of the step: a retry hours later sends the same content.
 * $dedupKey is unique — the same step can never owe the same person the
 * same email twice, whatever is replayed.
 *
 * Delivery state is mutable: PENDING → SENDING (claimed by exactly one
 * sender, DutySwapNotificationRepository::claim) → SENT / FAILED (retried
 * by `app:duty-swaps:maintain` until MAX_ATTEMPTS) / CANCELLED. Each attempt
 * is traced ($attempts, $lastAttemptAt, $lastError). SENT means the SMTP
 * server accepted the message, never that it was read.
 */
#[ORM\Entity(repositoryClass: DutySwapNotificationRepository::class)]
#[ORM\Table(name: 'duty_swap_notifications')]
#[ORM\UniqueConstraint(name: 'uniq_duty_swap_notifications_dedup', columns: ['dedup_key'])]
#[ORM\Index(columns: ['status'], name: 'idx_duty_swap_notifications_status')]
class DutySwapNotification
{
    public const MAX_ATTEMPTS = 5;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: DutySwapRequest::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private DutySwapRequest $request;

    #[ORM\ManyToOne(targetEntity: DutySwapProposal::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'RESTRICT')]
    private ?DutySwapProposal $proposal;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private User $recipient;

    #[ORM\Column(length: 30, enumType: DutySwapNotificationKind::class)]
    private DutySwapNotificationKind $kind;

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON)]
    private array $payload;

    #[ORM\Column(length: 200)]
    private string $dedupKey;

    #[ORM\Column(length: 10, enumType: DutySwapNotificationStatus::class)]
    private DutySwapNotificationStatus $status = DutySwapNotificationStatus::PENDING;

    #[ORM\Column]
    private int $attempts = 0;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $claimedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastAttemptAt = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $lastError = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $sentAt = null;

    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(DutySwapRequest $request, ?DutySwapProposal $proposal, User $recipient, DutySwapNotificationKind $kind, array $payload, \DateTimeImmutable $createdAt)
    {
        $this->request = $request;
        $this->proposal = $proposal;
        $this->recipient = $recipient;
        $this->kind = $kind;
        $this->payload = $payload;
        $this->createdAt = $createdAt;
        $this->dedupKey = implode(':', [$kind->value, (string) $request->getStableId(), null !== $proposal ? (string) $proposal->getStableId() : '-', (string) $recipient->getStableId()]);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRequest(): DutySwapRequest
    {
        return $this->request;
    }

    public function getProposal(): ?DutySwapProposal
    {
        return $this->proposal;
    }

    public function getRecipient(): User
    {
        return $this->recipient;
    }

    public function getKind(): DutySwapNotificationKind
    {
        return $this->kind;
    }

    /**
     * @return array<string, mixed>
     */
    public function getPayload(): array
    {
        return $this->payload;
    }

    public function getDedupKey(): string
    {
        return $this->dedupKey;
    }

    public function getStatus(): DutySwapNotificationStatus
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

    public function getLastAttemptAt(): ?\DateTimeImmutable
    {
        return $this->lastAttemptAt;
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    public function getSentAt(): ?\DateTimeImmutable
    {
        return $this->sentAt;
    }

    /** Only after a successful claim (SENDING). */
    public function markSent(\DateTimeImmutable $at): void
    {
        $this->status = DutySwapNotificationStatus::SENT;
        $this->sentAt = $at;
        $this->lastAttemptAt = $at;
        $this->lastError = null;
    }

    /** Only after a successful claim (SENDING). */
    public function markFailed(\DateTimeImmutable $at, string $error): void
    {
        $this->status = DutySwapNotificationStatus::FAILED;
        $this->lastAttemptAt = $at;
        $this->lastError = mb_substr($error, 0, 500);
    }

    /** Only after a successful claim (SENDING): the account was deactivated meanwhile. */
    public function cancel(): void
    {
        $this->status = DutySwapNotificationStatus::CANCELLED;
    }
}
