<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PlanningSnapshotAvailabilityPeriodRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * A frozen copy of one UserAvailabilityPeriod intersecting the
 * PlanningPeriod at snapshot time (docs/planning-generation.md
 * §Indisponibilités et préférences). Both UNAVAILABLE and PREFER_DUTY are
 * captured — the HARD/SOFT distinction ($type) is preserved verbatim, but
 * *applying* it (eligibility=false vs a preference score) is explicitly
 * out of scope for this lot (no EligibilityService yet).
 */
#[ORM\Entity(repositoryClass: PlanningSnapshotAvailabilityPeriodRepository::class)]
#[ORM\Table(name: 'planning_snapshot_availability_periods')]
#[ORM\Index(columns: ['snapshot_member_id'], name: 'idx_snapshot_availability_periods_member_id')]
class PlanningSnapshotAvailabilityPeriod
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: PlanningSnapshotMember::class, inversedBy: 'availabilityPeriods')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private PlanningSnapshotMember $snapshotMember;

    #[ORM\Column(type: 'uuid')]
    private Uuid $sourceAvailabilityStableId;

    #[ORM\Column(length: 20, enumType: UserAvailabilityType::class)]
    private UserAvailabilityType $type;

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
        Uuid $sourceAvailabilityStableId,
        UserAvailabilityType $type,
        \DateTimeImmutable $startsAt,
        \DateTimeImmutable $endsAt,
        \DateTimeImmutable $sourceCreatedAt,
        \DateTimeImmutable $sourceUpdatedAt,
    ) {
        $this->snapshotMember = $snapshotMember;
        $this->sourceAvailabilityStableId = $sourceAvailabilityStableId;
        $this->type = $type;
        $this->startsAt = $startsAt;
        $this->endsAt = $endsAt;
        $this->sourceCreatedAt = $sourceCreatedAt;
        $this->sourceUpdatedAt = $sourceUpdatedAt;
        $this->createdAt = new \DateTimeImmutable();
        $snapshotMember->addAvailabilityPeriod($this);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSnapshotMember(): PlanningSnapshotMember
    {
        return $this->snapshotMember;
    }

    public function getSourceAvailabilityStableId(): Uuid
    {
        return $this->sourceAvailabilityStableId;
    }

    public function getType(): UserAvailabilityType
    {
        return $this->type;
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
     * Exact-instant overlap check against a Duty's real start/end — never
     * $duty->getLocalDate() (docs/eligibility.md §4.3: a personal
     * unavailability must be compared against the real instants, exactly
     * like Duty::overlapsWith() compares two duties).
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
