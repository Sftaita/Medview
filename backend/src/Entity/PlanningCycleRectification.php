<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PlanningCycleRectificationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * One rectification of a locked period's end (docs/decisions.md D211) —
 * append-only: no setter, and a database trigger refuses UPDATE and DELETE.
 * The boundary with the next period was moved earlier, over trailing days of
 * the locked period that held no duty; who did it, why, and both ends.
 */
#[ORM\Entity(repositoryClass: PlanningCycleRectificationRepository::class)]
#[ORM\Table(name: 'planning_cycle_rectifications')]
#[ORM\UniqueConstraint(name: 'uniq_planning_cycle_rectifications_stable_id', columns: ['stable_id'])]
#[ORM\Index(columns: ['planning_id'], name: 'idx_planning_cycle_rectifications_planning_id')]
#[ORM\Index(columns: ['planning_cycle_id'], name: 'idx_planning_cycle_rectifications_cycle_id')]
#[ORM\Index(columns: ['next_cycle_id'], name: 'idx_planning_cycle_rectifications_next_cycle_id')]
#[ORM\Index(columns: ['rectified_by_id'], name: 'idx_planning_cycle_rectifications_rectified_by_id')]
class PlanningCycleRectification
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

    #[ORM\ManyToOne(targetEntity: PlanningCycle::class)]
    #[ORM\JoinColumn(name: 'planning_cycle_id', nullable: false, onDelete: 'RESTRICT')]
    private PlanningCycle $cycle;

    #[ORM\ManyToOne(targetEntity: PlanningCycle::class)]
    #[ORM\JoinColumn(name: 'next_cycle_id', nullable: false, onDelete: 'RESTRICT')]
    private PlanningCycle $nextCycle;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $previousEndsAt;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $newEndsAt;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastDutyDay;

    #[ORM\Column(type: Types::TEXT)]
    private string $reason;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private User $rectifiedBy;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(PlanningCycle $cycle, PlanningCycle $nextCycle, \DateTimeImmutable $previousEndsAt, \DateTimeImmutable $newEndsAt, ?\DateTimeImmutable $lastDutyDay, string $reason, User $rectifiedBy, \DateTimeImmutable $createdAt)
    {
        $this->stableId = Uuid::v7();
        $this->planning = $cycle->getPlanning();
        $this->cycle = $cycle;
        $this->nextCycle = $nextCycle;
        $this->previousEndsAt = $previousEndsAt;
        $this->newEndsAt = $newEndsAt;
        $this->lastDutyDay = $lastDutyDay;
        $this->reason = $reason;
        $this->rectifiedBy = $rectifiedBy;
        $this->createdAt = $createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getStableId(): Uuid
    {
        return $this->stableId;
    }

    public function getCycle(): PlanningCycle
    {
        return $this->cycle;
    }

    public function getNextCycle(): PlanningCycle
    {
        return $this->nextCycle;
    }

    public function getPreviousEndsAt(): \DateTimeImmutable
    {
        return $this->previousEndsAt;
    }

    public function getNewEndsAt(): \DateTimeImmutable
    {
        return $this->newEndsAt;
    }

    public function getLastDutyDay(): ?\DateTimeImmutable
    {
        return $this->lastDutyDay;
    }

    public function getReason(): string
    {
        return $this->reason;
    }

    public function getRectifiedBy(): User
    {
        return $this->rectifiedBy;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
