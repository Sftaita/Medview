<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PeriodPreparationReminderRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * One reminder of a period's preparation (docs/decisions.md D210): N days or
 * N weeks before the target generation date, at 08:00 Europe/Brussels, to the
 * people who have not confirmed their availabilities yet.
 *
 * $dueOn / $dueAt follow the target date while the reminder is SCHEDULED;
 * once it ran (or was skipped or cancelled) it is history and never changes.
 */
#[ORM\Entity(repositoryClass: PeriodPreparationReminderRepository::class)]
#[ORM\Table(name: 'period_preparation_reminders')]
#[ORM\UniqueConstraint(name: 'uniq_period_preparation_reminders_stable_id', columns: ['stable_id'])]
#[ORM\Index(columns: ['preparation_id'], name: 'idx_period_preparation_reminders_preparation_id')]
class PeriodPreparationReminder
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: 'uuid')]
    private Uuid $stableId;

    #[ORM\ManyToOne(targetEntity: PeriodPreparation::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private PeriodPreparation $preparation;

    #[ORM\Column]
    private int $offsetValue;

    #[ORM\Column(length: 4, enumType: ReminderOffsetUnit::class)]
    private ReminderOffsetUnit $offsetUnit;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $dueOn;

    #[ORM\Column]
    private \DateTimeImmutable $dueAt;

    #[ORM\Column(length: 12, enumType: PreparationActionState::class)]
    private PreparationActionState $state = PreparationActionState::SCHEDULED;

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $outcome = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $executedAt = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(PeriodPreparation $preparation, int $offsetValue, ReminderOffsetUnit $offsetUnit, \DateTimeImmutable $dueOn, \DateTimeImmutable $dueAt, \DateTimeImmutable $now)
    {
        $this->stableId = Uuid::v7();
        $this->preparation = $preparation;
        $this->offsetValue = $offsetValue;
        $this->offsetUnit = $offsetUnit;
        $this->dueOn = $dueOn;
        $this->dueAt = $dueAt;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getStableId(): Uuid
    {
        return $this->stableId;
    }

    public function getPreparation(): PeriodPreparation
    {
        return $this->preparation;
    }

    public function getOffsetValue(): int
    {
        return $this->offsetValue;
    }

    public function getOffsetUnit(): ReminderOffsetUnit
    {
        return $this->offsetUnit;
    }

    public function getDueOn(): \DateTimeImmutable
    {
        return $this->dueOn;
    }

    public function getDueAt(): \DateTimeImmutable
    {
        return $this->dueAt;
    }

    public function getState(): PreparationActionState
    {
        return $this->state;
    }

    public function isScheduled(): bool
    {
        return PreparationActionState::SCHEDULED === $this->state;
    }

    public function getOutcome(): ?string
    {
        return $this->outcome;
    }

    public function getExecutedAt(): ?\DateTimeImmutable
    {
        return $this->executedAt;
    }

    public function reschedule(int $offsetValue, ReminderOffsetUnit $offsetUnit, \DateTimeImmutable $dueOn, \DateTimeImmutable $dueAt, \DateTimeImmutable $now): void
    {
        if (!$this->isScheduled()) {
            throw new \LogicException('Only a scheduled reminder changes.');
        }
        $this->offsetValue = $offsetValue;
        $this->offsetUnit = $offsetUnit;
        $this->dueOn = $dueOn;
        $this->dueAt = $dueAt;
        $this->updatedAt = $now;
    }

    public function finish(PreparationActionState $state, string $outcome, \DateTimeImmutable $now): void
    {
        if (!$this->isScheduled() || PreparationActionState::SCHEDULED === $state) {
            throw new \LogicException('Only a scheduled reminder finishes, once.');
        }
        $this->state = $state;
        $this->outcome = $outcome;
        $this->executedAt = $now;
        $this->updatedAt = $now;
    }
}
