<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\AllocationFamilyRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * A PlanningTeam's own catalogue entry for an equity bucket — e.g.
 * "Week-end", "Semaine", "Samedi seul" — never a universal weekend/weekday
 * dichotomy hardcoded in the domain (docs/decisions.md D136). Deliberately
 * modeled exactly like `DutyType` (same shape, same team-scoped
 * uniqueness): a lightweight, versionable identity multiple `DutyPattern`s
 * can share, so "L", "Ma", "Me", "Je" (four separate one-day patterns) can
 * all point at the same WEEKDAY family while "V+S+D" points at a different
 * one — a plain string column on `DutyPattern` could not express that
 * sharing.
 *
 * `code`/`stableId` are immutable once assigned (no setter) — a
 * `DutyPattern` referencing this family embeds `stableId` inside a
 * `FairnessDimensionKey` wherever it materializes duties, and that
 * identity must never drift out from under an already-materialized Duty
 * (docs/planning-domain.md's "never recompute history" principle, applied
 * here to fairness-family classification instead of assignment history).
 * Only `name` (a display label) may change.
 */
#[ORM\Entity(repositoryClass: AllocationFamilyRepository::class)]
#[ORM\Table(name: 'allocation_families')]
#[ORM\UniqueConstraint(name: 'uniq_allocation_families_stable_id', columns: ['stable_id'])]
#[ORM\UniqueConstraint(name: 'uniq_allocation_families_team_code', columns: ['team_id', 'code'])]
#[ORM\UniqueConstraint(name: 'uniq_allocation_families_id_team_id', columns: ['id', 'team_id'])]
class AllocationFamily
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: 'uuid')]
    private Uuid $stableId;

    #[ORM\ManyToOne(targetEntity: PlanningTeam::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private PlanningTeam $team;

    /**
     * Stable within a PlanningTeam, unique within a PlanningTeam — the same
     * code may be reused freely by a different PlanningTeam.
     */
    #[ORM\Column(length: 50)]
    private string $code;

    #[ORM\Column(length: 150)]
    private string $name;

    #[ORM\Column]
    private bool $active = true;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(PlanningTeam $team, string $code, string $name)
    {
        $this->stableId = Uuid::v7();
        $this->team = $team;
        $this->code = $code;
        $this->name = $name;
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

    public function getTeam(): PlanningTeam
    {
        return $this->team;
    }

    public function getCode(): string
    {
        return $this->code;
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
