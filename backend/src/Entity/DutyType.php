<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\DutyTypeRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * A team's own catalogue entry for a kind of duty (e.g. DAY, NIGHT,
 * ON_CALL) — deliberately not a global application-wide enum, since each
 * team defines its own set (docs/allocation-algorithm.md §5/§X).
 *
 * $workloadValue drives the WEIGHTED_WORKLOAD fairness dimension
 * (requiredDemand(WEIGHTED_WORKLOAD) = Σ dutyType.workloadValue over
 * required duties). Stored as an exact Doctrine `decimal`, never a binary
 * float — the scaled-integer representation CP-SAT needs is an adapter
 * concern (docs/allocation-algorithm.md §22), not a storage concern:
 * keeping it decimal here avoids leaking a solver implementation detail
 * (the scale factor) into the domain and every admin-facing view.
 *
 * Skill/habilitation requirements are deliberately not modeled yet — that
 * belongs to the availability/eligibility lot, and a bare boolean flag
 * with no Skill entity behind it would be a half-built concept.
 */
#[ORM\Entity(repositoryClass: DutyTypeRepository::class)]
#[ORM\Table(name: 'duty_types')]
#[ORM\UniqueConstraint(name: 'uniq_duty_types_stable_id', columns: ['stable_id'])]
#[ORM\UniqueConstraint(name: 'uniq_duty_types_team_code', columns: ['team_id', 'code'])]
#[ORM\UniqueConstraint(name: 'uniq_duty_types_id_team_id', columns: ['id', 'team_id'])]
class DutyType
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: 'uuid')]
    private Uuid $stableId;

    #[ORM\ManyToOne(targetEntity: Team::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Team $team;

    /**
     * Stable within a Team, unique within a Team — the same code may be
     * reused freely by a different Team.
     */
    #[ORM\Column(length: 50)]
    private string $code;

    #[ORM\Column(length: 150)]
    private string $name;

    #[ORM\Column]
    private bool $active = true;

    #[ORM\Column(type: 'decimal', precision: 6, scale: 2)]
    private string $workloadValue;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(Team $team, string $code, string $name, float $workloadValue = 1.0)
    {
        if ($workloadValue <= 0) {
            throw new \InvalidArgumentException('workloadValue must be strictly positive.');
        }

        $this->stableId = Uuid::v7();
        $this->team = $team;
        $this->code = $code;
        $this->name = $name;
        $this->workloadValue = number_format($workloadValue, 2, '.', '');
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

    public function getTeam(): Team
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

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): void
    {
        $this->active = $active;
        $this->touch();
    }

    public function getWorkloadValue(): float
    {
        return (float) $this->workloadValue;
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
