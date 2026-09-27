<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\WeeklyDutyReminderRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * One weekly "Vos gardes de la semaine prochaine" email actually sent
 * (docs/decisions.md D146) — the idempotency key of the Saturday run: a
 * second run for the same (user, planning, week) sends nothing. Only ever
 * written for a person who really had at least one duty that week; never
 * a row, never an email, for someone with none. Append-only (trigger).
 */
#[ORM\Entity(repositoryClass: WeeklyDutyReminderRepository::class)]
#[ORM\Table(name: 'weekly_duty_reminders')]
#[ORM\UniqueConstraint(name: 'uniq_weekly_duty_reminders_week', columns: ['user_id', 'planning_id', 'week_start'])]
class WeeklyDutyReminder
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private User $user;

    #[ORM\ManyToOne(targetEntity: Planning::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Planning $planning;

    /** Monday of the reminded week, a calendar date in the Planning's timezone. */
    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $weekStart;

    #[ORM\Column]
    private int $dutyCount;

    #[ORM\Column]
    private \DateTimeImmutable $sentAt;

    public function __construct(User $user, Planning $planning, \DateTimeImmutable $weekStart, int $dutyCount, \DateTimeImmutable $sentAt)
    {
        $this->user = $user;
        $this->planning = $planning;
        $this->weekStart = $weekStart;
        $this->dutyCount = $dutyCount;
        $this->sentAt = $sentAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getPlanning(): Planning
    {
        return $this->planning;
    }

    public function getWeekStart(): \DateTimeImmutable
    {
        return $this->weekStart;
    }

    public function getDutyCount(): int
    {
        return $this->dutyCount;
    }

    public function getSentAt(): \DateTimeImmutable
    {
        return $this->sentAt;
    }
}
