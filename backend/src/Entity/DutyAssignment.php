<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\DutyAssignmentRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * One Duty's assignment within one PlanningGeneration
 * (docs/planning-generation.md §DutyAssignment). A Duty may hold different
 * assignments across different (historical) generations of the same
 * PlanningPeriod — only one per generation, enforced by the unique
 * constraint on ($generation, $duty).
 *
 * Carries both $teamMember (the live row, for normal operation) and
 * $snapshotMember (the frozen row from this generation's snapshot, for
 * historical interpretability even if $teamMember later leaves the team —
 * docs/planning-generation.md §15). The two cross-entity checks below
 * mirror the defense-in-depth already used throughout this domain (see
 * Duty's own constructor) — DutyAssignmentService is expected to validate
 * these first and produce a clean typed exception; these guards exist only
 * to catch a service bug, not as the user-facing validation path.
 */
#[ORM\Entity(repositoryClass: DutyAssignmentRepository::class)]
#[ORM\Table(name: 'duty_assignments')]
#[ORM\UniqueConstraint(name: 'uniq_duty_assignments_stable_id', columns: ['stable_id'])]
#[ORM\UniqueConstraint(name: 'uniq_duty_assignments_generation_duty', columns: ['generation_id', 'duty_id'])]
#[ORM\Index(columns: ['team_member_id'], name: 'idx_duty_assignments_team_member_id')]
class DutyAssignment
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: 'uuid')]
    private Uuid $stableId;

    #[ORM\ManyToOne(targetEntity: PlanningGeneration::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private PlanningGeneration $generation;

    #[ORM\ManyToOne(targetEntity: Duty::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Duty $duty;

    #[ORM\ManyToOne(targetEntity: TeamMember::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private TeamMember $teamMember;

    #[ORM\ManyToOne(targetEntity: PlanningSnapshotMember::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private PlanningSnapshotMember $snapshotMember;

    #[ORM\Column(length: 10, enumType: DutyAssignmentSource::class)]
    private DutyAssignmentSource $source;

    #[ORM\Column]
    private bool $locked;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        PlanningGeneration $generation,
        Duty $duty,
        TeamMember $teamMember,
        PlanningSnapshotMember $snapshotMember,
        DutyAssignmentSource $source,
        bool $locked = false,
    ) {
        if ($duty->getPlanningPeriod() !== $generation->getPlanningPeriod()) {
            throw new \InvalidArgumentException('A DutyAssignment must use a Duty from the same PlanningPeriod as its PlanningGeneration.');
        }

        if ($teamMember->getTeam() !== $generation->getPlanningPeriod()->getTeam()) {
            throw new \InvalidArgumentException('A DutyAssignment must use a TeamMember from the same Team as its PlanningGeneration.');
        }

        if (!$snapshotMember->getSourceTeamMemberStableId()->equals($teamMember->getStableId())) {
            throw new \InvalidArgumentException('A DutyAssignment\'s snapshotMember must correspond to its TeamMember.');
        }

        $this->stableId = Uuid::v7();
        $this->generation = $generation;
        $this->duty = $duty;
        $this->teamMember = $teamMember;
        $this->snapshotMember = $snapshotMember;
        $this->source = $source;
        $this->locked = $locked;
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

    public function getGeneration(): PlanningGeneration
    {
        return $this->generation;
    }

    public function getDuty(): Duty
    {
        return $this->duty;
    }

    public function getTeamMember(): TeamMember
    {
        return $this->teamMember;
    }

    public function getSnapshotMember(): PlanningSnapshotMember
    {
        return $this->snapshotMember;
    }

    public function getSource(): DutyAssignmentSource
    {
        return $this->source;
    }

    public function isLocked(): bool
    {
        return $this->locked;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
