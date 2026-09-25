<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\DutyAssignmentEventRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * One real reassignment, append-only (docs/decisions.md D131) — same
 * pattern as PlanningAvailabilityReminder (D127): no setter, no deletion
 * path, a database trigger refuses UPDATE and DELETE too. "Who changed
 * what, when, from what to what" is always derived from these rows, never
 * from a mutable audit column.
 *
 * $previousAssignment is null exactly when the Duty had no current
 * assignment before this event (a previously NON COUVERTE duty being
 * filled for the first time) — never a magic sentinel row. $newAssignment
 * is never null: an event only exists once a new current DutyAssignment
 * really was persisted. $wasPublished freezes whether the PlanningPeriod
 * was already PUBLISHED at the moment of the change — needed to decide,
 * after the fact, whether that change should have triggered a
 * notification email (D131 §5), without ever re-deriving it from the
 * period's current (possibly since-changed) status.
 */
#[ORM\Entity(repositoryClass: DutyAssignmentEventRepository::class)]
#[ORM\Table(name: 'duty_assignment_events')]
#[ORM\UniqueConstraint(name: 'uniq_duty_assignment_events_stable_id', columns: ['stable_id'])]
#[ORM\Index(columns: ['planning_id', 'occurred_at'], name: 'idx_duty_assignment_events_planning')]
#[ORM\Index(columns: ['duty_id'], name: 'idx_duty_assignment_events_duty')]
class DutyAssignmentEvent
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

    #[ORM\ManyToOne(targetEntity: PlanningGeneration::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private PlanningGeneration $generation;

    #[ORM\ManyToOne(targetEntity: Duty::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Duty $duty;

    #[ORM\ManyToOne(targetEntity: DutyAssignment::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'RESTRICT')]
    private ?DutyAssignment $previousAssignment;

    #[ORM\ManyToOne(targetEntity: DutyAssignment::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private DutyAssignment $newAssignment;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private User $author;

    #[ORM\Column]
    private bool $wasPublished;

    #[ORM\Column]
    private \DateTimeImmutable $occurredAt;

    public function __construct(
        Planning $planning,
        PlanningGeneration $generation,
        Duty $duty,
        ?DutyAssignment $previousAssignment,
        DutyAssignment $newAssignment,
        User $author,
        bool $wasPublished,
        \DateTimeImmutable $occurredAt,
    ) {
        $this->stableId = Uuid::v7();
        $this->planning = $planning;
        $this->generation = $generation;
        $this->duty = $duty;
        $this->previousAssignment = $previousAssignment;
        $this->newAssignment = $newAssignment;
        $this->author = $author;
        $this->wasPublished = $wasPublished;
        $this->occurredAt = $occurredAt;
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

    public function getGeneration(): PlanningGeneration
    {
        return $this->generation;
    }

    public function getDuty(): Duty
    {
        return $this->duty;
    }

    public function getPreviousAssignment(): ?DutyAssignment
    {
        return $this->previousAssignment;
    }

    public function getNewAssignment(): DutyAssignment
    {
        return $this->newAssignment;
    }

    public function getAuthor(): User
    {
        return $this->author;
    }

    public function wasPublished(): bool
    {
        return $this->wasPublished;
    }

    public function getOccurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
