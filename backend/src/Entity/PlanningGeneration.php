<?php

declare(strict_types=1);

namespace App\Entity;

use App\Exception\InvalidPlanningGenerationTransitionException;
use App\Repository\PlanningGenerationRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * One attempt to generate a PlanningPeriod's duty roster
 * (docs/planning-generation.md). A PlanningPeriod may accumulate several
 * successive generations over time — creating a new one never overwrites
 * or deletes an earlier one (CLAUDE.md: history is never recalculated from
 * current state).
 *
 * $stableId is what DutyAssignment and any future audit trail reference —
 * never the auto-increment $id (D046).
 */
#[ORM\Entity(repositoryClass: PlanningGenerationRepository::class)]
#[ORM\Table(name: 'planning_generations')]
#[ORM\UniqueConstraint(name: 'uniq_planning_generations_stable_id', columns: ['stable_id'])]
#[ORM\Index(columns: ['planning_period_id'], name: 'idx_planning_generations_planning_period_id')]
class PlanningGeneration
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: 'uuid')]
    private Uuid $stableId;

    #[ORM\ManyToOne(targetEntity: PlanningPeriod::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private PlanningPeriod $planningPeriod;

    #[ORM\Column(length: 20, enumType: PlanningGenerationStatus::class)]
    private PlanningGenerationStatus $status;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'RESTRICT')]
    private ?User $createdBy = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(PlanningPeriod $planningPeriod, ?User $createdBy = null)
    {
        $this->stableId = Uuid::v7();
        $this->planningPeriod = $planningPeriod;
        $this->status = PlanningGenerationStatus::DRAFT;
        $this->createdBy = $createdBy;
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

    public function getPlanningPeriod(): PlanningPeriod
    {
        return $this->planningPeriod;
    }

    public function getStatus(): PlanningGenerationStatus
    {
        return $this->status;
    }

    /**
     * The only way this status ever changes — see
     * PlanningGenerationStatus::canTransitionTo() for the allowed graph.
     *
     * @throws InvalidPlanningGenerationTransitionException
     */
    public function transitionTo(PlanningGenerationStatus $target): void
    {
        if (!$this->status->canTransitionTo($target)) {
            throw new InvalidPlanningGenerationTransitionException($this->status, $target);
        }

        $this->status = $target;
        $this->touch();
    }

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
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
