<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PlanningPublicationRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * One real diffusion of a Planning's calendar (docs/decisions.md D143),
 * append-only like DutyAssignmentEvent (no setter, a database trigger
 * refuses UPDATE and DELETE).
 *
 * What was diffused is frozen in its PlanningPublicationEntry rows — one
 * per Duty of every active line, holding the PlanningTeamMember published
 * for it (or null when it was published uncovered). The most recent
 * publication is therefore the exact reference "Modifications non
 * publiées" is computed against: the current calendar
 * (DutyAssignment.current, D131) compared duty by duty with these entries —
 * never with an old generation's solver output, and never by replaying
 * DutyAssignmentEvent (A → B → A must read as "no change").
 */
#[ORM\Entity(repositoryClass: PlanningPublicationRepository::class)]
#[ORM\Table(name: 'planning_publications')]
#[ORM\Index(columns: ['planning_cycle_id'], name: 'idx_planning_publications_planning_cycle_id')]
#[ORM\UniqueConstraint(name: 'uniq_planning_publications_stable_id', columns: ['stable_id'])]
#[ORM\Index(columns: ['planning_id', 'published_at'], name: 'idx_planning_publications_planning')]
class PlanningPublication
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

    /**
     * The "période" of the Planning this row belongs to (docs/planning-cycles.md).
     * NOT NULL in the database since lot L2 (docs/decisions.md D187): always
     * set before the first flush.
     */
    #[ORM\ManyToOne(targetEntity: PlanningCycle::class)]
    #[ORM\JoinColumn(name: 'planning_cycle_id', nullable: false, onDelete: 'RESTRICT')]
    private ?PlanningCycle $cycle = null;

    #[ORM\Column(length: 10, enumType: PlanningPublicationKind::class)]
    private PlanningPublicationKind $kind;

    #[ORM\Column]
    private \DateTimeImmutable $publishedAt;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private User $publishedBy;

    /** Duties whose published assignee differs from the previous publication — 0 for FIRST. */
    #[ORM\Column]
    private int $changedDutyCount;

    /**
     * docs/decisions.md D196: once this publication was recorded, every line
     * that counted in its period was published and nothing required was
     * published uncovered. Frozen: never recomputed from what changed since.
     */
    #[ORM\Column]
    private bool $cycleComplete;

    public function __construct(Planning $planning, PlanningPublicationKind $kind, User $publishedBy, int $changedDutyCount, \DateTimeImmutable $publishedAt, bool $cycleComplete)
    {
        $this->stableId = Uuid::v7();
        $this->planning = $planning;
        $this->kind = $kind;
        $this->publishedBy = $publishedBy;
        $this->changedDutyCount = $changedDutyCount;
        $this->publishedAt = $publishedAt;
        $this->cycleComplete = $cycleComplete;
    }

    public function isCycleComplete(): bool
    {
        return $this->cycleComplete;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getStableId(): Uuid
    {
        return $this->stableId;
    }

    public function getCycle(): ?PlanningCycle
    {
        return $this->cycle;
    }

    /** Set once, before the row is first written: a row never changes cycle. */
    public function assignToCycle(PlanningCycle $cycle): void
    {
        if (null !== $this->cycle) {
            if ($this->cycle === $cycle) {
                return;
            }
            throw new \LogicException('A row never changes cycle.');
        }
        if ($cycle->getPlanning() !== $this->planning) {
            throw new \InvalidArgumentException('The cycle must belong to the same Planning.');
        }

        $this->cycle = $cycle;
    }

    public function getPlanning(): Planning
    {
        return $this->planning;
    }

    public function getKind(): PlanningPublicationKind
    {
        return $this->kind;
    }

    public function getPublishedAt(): \DateTimeImmutable
    {
        return $this->publishedAt;
    }

    public function getPublishedBy(): User
    {
        return $this->publishedBy;
    }

    public function getChangedDutyCount(): int
    {
        return $this->changedDutyCount;
    }
}
