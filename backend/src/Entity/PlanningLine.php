<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PlanningLineRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * One "ligne de garde" inside a Planning — e.g. "Garde principale" (Team
 * Seniors) or "Renfort" (Team Fellows). Each line runs its own mono-team
 * engine underneath via $planningPeriod: FairnessPeriod, Duty, DutyType,
 * PlanningRuleSet, snapshot, eligibility all stay exactly as scoped to one
 * PlanningTeam as they were before Planning existed (docs/planning.md §4)
 * — this entity is purely the aggregator's bookkeeping, never itself read
 * by PlanningSnapshotService/EligibilityService.
 *
 * $planningTeam and $planningLine are deliberately two separate entities
 * even though v1 enforces a strict 1:1 relationship (unique on
 * $planningTeam — see migrations): a PlanningTeam is only ever created
 * by, and immediately consumed by, exactly one PlanningLine
 * (PlanningLineService::addLine(), docs/decisions.md D079). Keeping them
 * distinct is what lets a future version put more than one line on the
 * same PlanningTeam without a domain rewrite (docs/decisions.md D073,
 * still valid under the new model).
 */
#[ORM\Entity(repositoryClass: PlanningLineRepository::class)]
#[ORM\Table(name: 'planning_lines')]
#[ORM\UniqueConstraint(name: 'uniq_planning_lines_stable_id', columns: ['stable_id'])]
#[ORM\UniqueConstraint(name: 'uniq_planning_lines_planning_team', columns: ['planning_team_id'])]
#[ORM\UniqueConstraint(name: 'uniq_planning_lines_planning_period_id', columns: ['planning_period_id'])]
#[ORM\Index(columns: ['planning_id'], name: 'idx_planning_lines_planning_id')]
class PlanningLine
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

    #[ORM\ManyToOne(targetEntity: PlanningTeam::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private PlanningTeam $planningTeam;

    #[ORM\ManyToOne(targetEntity: PlanningPeriod::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private PlanningPeriod $planningPeriod;

    #[ORM\Column(length: 150)]
    private string $name;

    #[ORM\Column(length: 20, enumType: PlanningLineType::class)]
    private PlanningLineType $type;

    #[ORM\Column]
    private int $position;

    #[ORM\Column]
    private bool $active = true;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        Planning $planning,
        PlanningTeam $planningTeam,
        PlanningPeriod $planningPeriod,
        string $name,
        PlanningLineType $type,
        int $position,
    ) {
        if ($planningTeam->getPlanning() !== $planning) {
            throw new \InvalidArgumentException('A PlanningLine must use a PlanningTeam from the same Planning.');
        }

        if ($planningPeriod->getTeam() !== $planningTeam) {
            throw new \InvalidArgumentException('A PlanningLine must use a PlanningPeriod from the same PlanningTeam.');
        }

        if ($planningPeriod->getStartsAt() != $planning->getStartsAt() || $planningPeriod->getEndsAt() != $planning->getEndsAt()) {
            throw new \InvalidArgumentException('A PlanningLine\'s PlanningPeriod must exactly match its Planning\'s date range (docs/planning.md §5, docs/decisions.md D075).');
        }

        $this->stableId = Uuid::v7();
        $this->planning = $planning;
        $this->planningTeam = $planningTeam;
        $this->planningPeriod = $planningPeriod;
        $this->name = $name;
        $this->type = $type;
        $this->position = $position;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
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

    public function getPlanningTeam(): PlanningTeam
    {
        return $this->planningTeam;
    }

    public function getPlanningPeriod(): PlanningPeriod
    {
        return $this->planningPeriod;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function rename(string $name): void
    {
        $this->name = $name;
        $this->touch();
    }

    public function getType(): PlanningLineType
    {
        return $this->type;
    }

    public function isPrimary(): bool
    {
        return PlanningLineType::PRIMARY === $this->type;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): void
    {
        $this->active = $active;
        $this->touch();
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
