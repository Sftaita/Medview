<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PlanningSnapshotMemberRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * The frozen structural identity of one relevant TeamMember at snapshot
 * time (docs/planning-generation.md §Membres). Deliberately references the
 * source TeamMember/User only by their $stableId *value*, never by a
 * Doctrine relation — reading through a live FK later would silently
 * follow whatever that row has become (renamed, role changed, membership
 * closed), which is exactly what a snapshot must never do.
 *
 * $role is carried for audit/display only, per docs/planning-generation.md
 * — never read by the future engine, which decides eligibility from
 * membership dates and participation, not from role.
 *
 * $active freezes User::isActive() at snapshot time (docs/eligibility.md
 * §USER_INACTIVE, D067) — added in the eligibility lot specifically so
 * EligibilityService never has to fall back to reading the live User for a
 * datum the snapshot should have captured in the first place.
 */
#[ORM\Entity(repositoryClass: PlanningSnapshotMemberRepository::class)]
#[ORM\Table(name: 'planning_snapshot_members')]
#[ORM\Index(columns: ['snapshot_id'], name: 'idx_snapshot_members_snapshot_id')]
#[ORM\Index(columns: ['snapshot_id', 'source_team_member_stable_id'], name: 'idx_snapshot_members_snapshot_team_member')]
class PlanningSnapshotMember
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: PlanningSnapshot::class, inversedBy: 'members')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private PlanningSnapshot $snapshot;

    #[ORM\Column(type: 'uuid')]
    private Uuid $sourceTeamMemberStableId;

    #[ORM\Column(type: 'uuid')]
    private Uuid $sourceUserStableId;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $membershipStart;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $membershipEnd;

    #[ORM\Column(length: 20, enumType: TeamMemberRole::class)]
    private TeamMemberRole $role;

    #[ORM\Column]
    private bool $active;

    /**
     * @var Collection<int, PlanningSnapshotParticipationPeriod>
     */
    #[ORM\OneToMany(targetEntity: PlanningSnapshotParticipationPeriod::class, mappedBy: 'snapshotMember')]
    private Collection $participationPeriods;

    /**
     * @var Collection<int, PlanningSnapshotAvailabilityPeriod>
     */
    #[ORM\OneToMany(targetEntity: PlanningSnapshotAvailabilityPeriod::class, mappedBy: 'snapshotMember')]
    private Collection $availabilityPeriods;

    /**
     * @var Collection<int, PlanningSnapshotNonParticipationPeriod>
     */
    #[ORM\OneToMany(targetEntity: PlanningSnapshotNonParticipationPeriod::class, mappedBy: 'snapshotMember')]
    private Collection $nonParticipationPeriods;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        PlanningSnapshot $snapshot,
        Uuid $sourceTeamMemberStableId,
        Uuid $sourceUserStableId,
        \DateTimeImmutable $membershipStart,
        ?\DateTimeImmutable $membershipEnd,
        TeamMemberRole $role,
        bool $active,
    ) {
        $this->snapshot = $snapshot;
        $this->sourceTeamMemberStableId = $sourceTeamMemberStableId;
        $this->sourceUserStableId = $sourceUserStableId;
        $this->membershipStart = $membershipStart;
        $this->membershipEnd = $membershipEnd;
        $this->role = $role;
        $this->active = $active;
        $this->participationPeriods = new ArrayCollection();
        $this->availabilityPeriods = new ArrayCollection();
        $this->nonParticipationPeriods = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
        $snapshot->addMember($this);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSnapshot(): PlanningSnapshot
    {
        return $this->snapshot;
    }

    public function getSourceTeamMemberStableId(): Uuid
    {
        return $this->sourceTeamMemberStableId;
    }

    public function getSourceUserStableId(): Uuid
    {
        return $this->sourceUserStableId;
    }

    public function getMembershipStart(): \DateTimeImmutable
    {
        return $this->membershipStart;
    }

    public function getMembershipEnd(): ?\DateTimeImmutable
    {
        return $this->membershipEnd;
    }

    public function getRole(): TeamMemberRole
    {
        return $this->role;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    /**
     * Date-aware membership check, mirroring TeamMember::isActiveAt() —
     * the frozen equivalent used by EligibilityService for
     * MEMBERSHIP_OUT_OF_RANGE (docs/eligibility.md).
     */
    public function coversLocalDate(\DateTimeImmutable $localDate): bool
    {
        if ($localDate < $this->membershipStart) {
            return false;
        }

        return null === $this->membershipEnd || $localDate < $this->membershipEnd;
    }

    /**
     * @return Collection<int, PlanningSnapshotParticipationPeriod>
     */
    public function getParticipationPeriods(): Collection
    {
        return $this->participationPeriods;
    }

    /**
     * @internal
     */
    public function addParticipationPeriod(PlanningSnapshotParticipationPeriod $period): void
    {
        if (!$this->participationPeriods->contains($period)) {
            $this->participationPeriods->add($period);
        }
    }

    /**
     * @return Collection<int, PlanningSnapshotAvailabilityPeriod>
     */
    public function getAvailabilityPeriods(): Collection
    {
        return $this->availabilityPeriods;
    }

    /**
     * @internal
     */
    public function addAvailabilityPeriod(PlanningSnapshotAvailabilityPeriod $period): void
    {
        if (!$this->availabilityPeriods->contains($period)) {
            $this->availabilityPeriods->add($period);
        }
    }

    /**
     * @return Collection<int, PlanningSnapshotNonParticipationPeriod>
     */
    public function getNonParticipationPeriods(): Collection
    {
        return $this->nonParticipationPeriods;
    }

    /**
     * @internal
     */
    public function addNonParticipationPeriod(PlanningSnapshotNonParticipationPeriod $period): void
    {
        if (!$this->nonParticipationPeriods->contains($period)) {
            $this->nonParticipationPeriods->add($period);
        }
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
