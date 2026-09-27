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

    public function __construct(Planning $planning, PlanningPublicationKind $kind, User $publishedBy, int $changedDutyCount, \DateTimeImmutable $publishedAt)
    {
        $this->stableId = Uuid::v7();
        $this->planning = $planning;
        $this->kind = $kind;
        $this->publishedBy = $publishedBy;
        $this->changedDutyCount = $changedDutyCount;
        $this->publishedAt = $publishedAt;
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
