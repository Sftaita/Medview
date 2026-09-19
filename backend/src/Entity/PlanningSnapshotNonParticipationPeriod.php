<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PlanningSnapshotNonParticipationPeriodRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * A frozen copy of one TeamMemberNonParticipationPeriod intersecting the
 * PlanningPeriod at snapshot time (docs/planning-generation.md
 * §Non-participation administrative). Will back the future engine's
 * structuralOpportunity = 0 rule — not computed in this lot.
 */
#[ORM\Entity(repositoryClass: PlanningSnapshotNonParticipationPeriodRepository::class)]
#[ORM\Table(name: 'planning_snapshot_non_participation_periods')]
#[ORM\Index(columns: ['snapshot_member_id'], name: 'idx_snapshot_non_participation_periods_member_id')]
class PlanningSnapshotNonParticipationPeriod
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: PlanningSnapshotMember::class, inversedBy: 'nonParticipationPeriods')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private PlanningSnapshotMember $snapshotMember;

    #[ORM\Column(type: 'uuid')]
    private Uuid $sourceNonParticipationStableId;

    #[ORM\Column(type: 'datetimetz_immutable')]
    private \DateTimeImmutable $startsAt;

    #[ORM\Column(type: 'datetimetz_immutable')]
    private \DateTimeImmutable $endsAt;

    #[ORM\Column]
    private \DateTimeImmutable $sourceCreatedAt;

    #[ORM\Column]
    private \DateTimeImmutable $sourceUpdatedAt;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        PlanningSnapshotMember $snapshotMember,
        Uuid $sourceNonParticipationStableId,
        \DateTimeImmutable $startsAt,
        \DateTimeImmutable $endsAt,
        \DateTimeImmutable $sourceCreatedAt,
        \DateTimeImmutable $sourceUpdatedAt,
    ) {
        $this->snapshotMember = $snapshotMember;
        $this->sourceNonParticipationStableId = $sourceNonParticipationStableId;
        $this->startsAt = $startsAt;
        $this->endsAt = $endsAt;
        $this->sourceCreatedAt = $sourceCreatedAt;
        $this->sourceUpdatedAt = $sourceUpdatedAt;
        $this->createdAt = new \DateTimeImmutable();
        $snapshotMember->addNonParticipationPeriod($this);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSnapshotMember(): PlanningSnapshotMember
    {
        return $this->snapshotMember;
    }

    public function getSourceNonParticipationStableId(): Uuid
    {
        return $this->sourceNonParticipationStableId;
    }

    public function getStartsAt(): \DateTimeImmutable
    {
        return $this->startsAt;
    }

    public function getEndsAt(): \DateTimeImmutable
    {
        return $this->endsAt;
    }

    public function getSourceCreatedAt(): \DateTimeImmutable
    {
        return $this->sourceCreatedAt;
    }

    public function getSourceUpdatedAt(): \DateTimeImmutable
    {
        return $this->sourceUpdatedAt;
    }

    /**
     * Exact-instant overlap check against a Duty's real start/end — same
     * treatment as PlanningSnapshotAvailabilityPeriod::overlapsWith().
     */
    public function overlapsWith(Duty $duty): bool
    {
        return $this->startsAt < $duty->getEndsAt() && $duty->getStartsAt() < $this->endsAt;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
