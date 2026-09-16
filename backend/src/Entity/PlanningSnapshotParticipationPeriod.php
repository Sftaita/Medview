<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PlanningSnapshotParticipationPeriodRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A frozen copy of one TeamMemberParticipationPeriod segment intersecting
 * the PlanningPeriod at snapshot time (docs/planning-generation.md
 * §Participation). No source-row identifier is carried: unlike
 * UserAvailabilityPeriod/TeamMemberNonParticipationPeriod,
 * TeamMemberParticipationPeriod has no $stableId (D056 deliberately left
 * it without one), so there is nothing stable to reference back to —
 * documented here rather than silently omitted.
 */
#[ORM\Entity(repositoryClass: PlanningSnapshotParticipationPeriodRepository::class)]
#[ORM\Table(name: 'planning_snapshot_participation_periods')]
#[ORM\Index(columns: ['snapshot_member_id'], name: 'idx_snapshot_participation_periods_member_id')]
class PlanningSnapshotParticipationPeriod
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: PlanningSnapshotMember::class, inversedBy: 'participationPeriods')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private PlanningSnapshotMember $snapshotMember;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $validFrom;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $validTo;

    #[ORM\Column(type: 'decimal', precision: 6, scale: 4)]
    private string $participationFactor;

    #[ORM\Column(length: 40, enumType: ParticipationFactorChangeReason::class)]
    private ParticipationFactorChangeReason $changeReason;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        PlanningSnapshotMember $snapshotMember,
        \DateTimeImmutable $validFrom,
        ?\DateTimeImmutable $validTo,
        float $participationFactor,
        ParticipationFactorChangeReason $changeReason,
    ) {
        $this->snapshotMember = $snapshotMember;
        $this->validFrom = $validFrom;
        $this->validTo = $validTo;
        $this->participationFactor = number_format($participationFactor, 4, '.', '');
        $this->changeReason = $changeReason;
        $this->createdAt = new \DateTimeImmutable();
        $snapshotMember->addParticipationPeriod($this);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSnapshotMember(): PlanningSnapshotMember
    {
        return $this->snapshotMember;
    }

    public function getValidFrom(): \DateTimeImmutable
    {
        return $this->validFrom;
    }

    public function getValidTo(): ?\DateTimeImmutable
    {
        return $this->validTo;
    }

    public function toFloat(): float
    {
        return (float) $this->participationFactor;
    }

    public function getChangeReason(): ParticipationFactorChangeReason
    {
        return $this->changeReason;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
