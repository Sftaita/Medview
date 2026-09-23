<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PlanningAvailabilityReminderRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * One reminder that was really delivered ("please review your
 * availabilities") — append-only audit (docs/decisions.md D127): there is
 * no setter and no deletion path (a database trigger refuses UPDATE and
 * DELETE too), and "the last reminder" is always derived from these rows,
 * never from a mutable `lastReminderAt` column.
 *
 * A row only exists once the mail transport accepted the message, so
 * "last reminder" can never claim a send that did not happen.
 *
 * $recipient is the User, not a PlanningTeamMember stint: like the
 * availability responses (D082, D120) a person who leaves and rejoins a
 * planning is one recipient, and the reminder history follows them.
 */
#[ORM\Entity(repositoryClass: PlanningAvailabilityReminderRepository::class)]
#[ORM\Table(name: 'planning_availability_reminders')]
#[ORM\UniqueConstraint(name: 'uniq_planning_availability_reminders_stable_id', columns: ['stable_id'])]
#[ORM\Index(columns: ['planning_id', 'recipient_id', 'sent_at'], name: 'idx_planning_availability_reminders_lookup')]
class PlanningAvailabilityReminder
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: 'uuid')]
    private Uuid $stableId;

    #[ORM\ManyToOne(targetEntity: Planning::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Planning $planning;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private User $recipient;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private User $sentBy;

    #[ORM\Column(length: 20, enumType: ReminderChannel::class)]
    private ReminderChannel $channel;

    /** True when sent by "remind everybody still pending" rather than to one person. */
    #[ORM\Column]
    private bool $bulk;

    /** How many open collections (windows) the recipient had still not answered at that moment. */
    #[ORM\Column]
    private int $pendingCollectionCount;

    #[ORM\Column]
    private \DateTimeImmutable $sentAt;

    public function __construct(
        Planning $planning,
        User $recipient,
        User $sentBy,
        ReminderChannel $channel,
        bool $bulk,
        int $pendingCollectionCount,
        \DateTimeImmutable $sentAt,
    ) {
        $this->stableId = Uuid::v7();
        $this->planning = $planning;
        $this->recipient = $recipient;
        $this->sentBy = $sentBy;
        $this->channel = $channel;
        $this->bulk = $bulk;
        $this->pendingCollectionCount = $pendingCollectionCount;
        $this->sentAt = $sentAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getStableId(): Uuid
    {
        return $this->stableId;
    }

    public function getPlanning(): Planning
    {
        return $this->planning;
    }

    public function getRecipient(): User
    {
        return $this->recipient;
    }

    public function getSentBy(): User
    {
        return $this->sentBy;
    }

    public function getChannel(): ReminderChannel
    {
        return $this->channel;
    }

    public function isBulk(): bool
    {
        return $this->bulk;
    }

    public function getPendingCollectionCount(): int
    {
        return $this->pendingCollectionCount;
    }

    public function getSentAt(): \DateTimeImmutable
    {
        return $this->sentAt;
    }
}
