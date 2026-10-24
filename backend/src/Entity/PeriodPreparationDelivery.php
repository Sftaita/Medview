<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PeriodPreparationDeliveryRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * One email of a period's preparation to one person — the announcement or a
 * reminder (docs/decisions.md D210). An outbox row, as D172: PENDING →
 * SENDING (claimed by exactly one sender,
 * PeriodPreparationDeliveryRepository::claim) → SENT, FAILED (retried until
 * MAX_ATTEMPTS) or CANCELLED (no longer relevant when its turn came: the
 * person confirmed meanwhile, the generation was launched…).
 *
 * The rows are the record of who was invited and reminded, and when. One
 * announcement per person and period, one row per person and reminder
 * (unique indexes): a restart or a second run never queues a second email.
 * A SENT (or SENDING) row counts as "people were written to" for the date
 * rules of D208 (PlanningCycleRepository::dateFacts()).
 */
#[ORM\Entity(repositoryClass: PeriodPreparationDeliveryRepository::class)]
#[ORM\Table(name: 'period_preparation_deliveries')]
#[ORM\Index(columns: ['preparation_id'], name: 'idx_period_preparation_deliveries_preparation_id')]
#[ORM\Index(columns: ['reminder_id'], name: 'idx_period_preparation_deliveries_reminder_id')]
#[ORM\Index(columns: ['recipient_id'], name: 'idx_period_preparation_deliveries_recipient_id')]
#[ORM\Index(columns: ['planning_cycle_id', 'status'], name: 'idx_period_preparation_deliveries_cycle')]
class PeriodPreparationDelivery
{
    public const MAX_ATTEMPTS = 5;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: PeriodPreparation::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private PeriodPreparation $preparation;

    #[ORM\ManyToOne(targetEntity: PlanningCycle::class)]
    #[ORM\JoinColumn(name: 'planning_cycle_id', nullable: false, onDelete: 'RESTRICT')]
    private PlanningCycle $cycle;

    #[ORM\Column(length: 12, enumType: PreparationDeliveryKind::class)]
    private PreparationDeliveryKind $kind;

    #[ORM\ManyToOne(targetEntity: PeriodPreparationReminder::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'RESTRICT')]
    private ?PeriodPreparationReminder $reminder;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private User $recipient;

    #[ORM\Column(length: 10, enumType: PreparationDeliveryStatus::class)]
    private PreparationDeliveryStatus $status = PreparationDeliveryStatus::PENDING;

    #[ORM\Column]
    private int $attempts = 0;

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $cancelReason = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $claimedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $sentAt = null;

    public function __construct(PeriodPreparation $preparation, PreparationDeliveryKind $kind, ?PeriodPreparationReminder $reminder, User $recipient, \DateTimeImmutable $now)
    {
        if ((PreparationDeliveryKind::REMINDER === $kind) !== (null !== $reminder)) {
            throw new \LogicException('A reminder email names its reminder; an announcement does not.');
        }
        $this->preparation = $preparation;
        $this->cycle = $preparation->getCycle();
        $this->kind = $kind;
        $this->reminder = $reminder;
        $this->recipient = $recipient;
        $this->createdAt = $now;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPreparation(): PeriodPreparation
    {
        return $this->preparation;
    }

    public function getCycle(): PlanningCycle
    {
        return $this->cycle;
    }

    public function getKind(): PreparationDeliveryKind
    {
        return $this->kind;
    }

    public function getReminder(): ?PeriodPreparationReminder
    {
        return $this->reminder;
    }

    public function getRecipient(): User
    {
        return $this->recipient;
    }

    public function getStatus(): PreparationDeliveryStatus
    {
        return $this->status;
    }

    public function getAttempts(): int
    {
        return $this->attempts;
    }

    public function getCancelReason(): ?string
    {
        return $this->cancelReason;
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

    /** Only after a claim (the row is SENDING). */
    public function markSent(\DateTimeImmutable $now): void
    {
        $this->status = PreparationDeliveryStatus::SENT;
        $this->sentAt = $now;
    }

    public function markFailed(): void
    {
        $this->status = PreparationDeliveryStatus::FAILED;
    }

    public function cancel(string $reason): void
    {
        if (PreparationDeliveryStatus::SENT === $this->status) {
            throw new \LogicException('A sent email cannot be cancelled.');
        }
        $this->status = PreparationDeliveryStatus::CANCELLED;
        $this->cancelReason = $reason;
    }
}
