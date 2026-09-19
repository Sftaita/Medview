<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PlanningTeamRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The population of one PlanningLine: a set of members scoped to exactly
 * one Planning (see docs/decisions.md D079). A PlanningTeam is never
 * shared between Plannings and never referenced by a client when creating
 * a line — it is always created inline by PlanningLineService together
 * with its PlanningLine, in the same transaction.
 *
 * Deliberately holds no reference to its members (see PlanningTeamMember)
 * — a User belongs to zero, one or several PlanningTeams, so the
 * association always lives on the join entity, never as a field on either
 * side pretending there is only one.
 *
 * $stableId (not the auto-increment $id) is the identifier every
 * reproducible calculation, snapshot, tie-break and export must use — see
 * docs/planning-domain.md "Identifiants stables" and
 * docs/allocation-algorithm.md §13-14.
 */
#[ORM\Entity(repositoryClass: PlanningTeamRepository::class)]
#[ORM\Table(name: 'planning_teams')]
#[ORM\UniqueConstraint(name: 'uniq_planning_teams_stable_id', columns: ['stable_id'])]
#[ORM\UniqueConstraint(name: 'uniq_planning_teams_id_planning_id', columns: ['id', 'planning_id'])]
class PlanningTeam
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

    #[ORM\Column(length: 150)]
    #[Assert\NotBlank]
    private string $name;

    #[ORM\Column]
    private bool $active = true;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(Planning $planning, string $name)
    {
        $this->stableId = Uuid::v7();
        $this->planning = $planning;
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

    public function getPlanning(): Planning
    {
        return $this->planning;
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

    /**
     * IANA timezone identifier used to resolve a Duty's wall-clock
     * start/end into an absolute instant (docs/allocation-algorithm.md
     * §Duty / Dates et temps). Delegated to the owning Planning — a
     * PlanningTeam never stores its own timezone, it would only ever be
     * able to drift from the Planning that defines its date range.
     */
    public function getTimezone(): string
    {
        return $this->planning->getTimezone();
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    /**
     * Deactivating a team never deletes it or its history — see
     * docs/planning-domain.md "Suppression et historique".
     */
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
