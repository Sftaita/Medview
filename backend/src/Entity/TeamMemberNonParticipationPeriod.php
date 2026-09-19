<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\TeamMemberNonParticipationPeriodRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * A temporary window where a PlanningTeamMember's administrative
 * participation is considered null for planning purposes — distinct from a
 * personal UserAvailabilityPeriod (docs/availability.md "Indisponibilité
 * vs non-participation"): this is scoped to one PlanningTeam only
 * (attached to PlanningTeamMember, not User), which since a membership is
 * itself Planning-scoped (docs/decisions.md D079) automatically means "this
 * User + this team + this Planning" with no separate concept needed. Future
 * structuralOpportunity() — not just eligibility — must be zero while it
 * applies. Also distinct from TeamMemberParticipationPeriod, which is the
 * structural participationFactor timeline, not a temporary administrative
 * window.
 *
 * Semi-open interval [$startsAt, $endsAt[ as absolute instants
 * (TIMESTAMPTZ, mirroring Duty/UserAvailabilityPeriod). Mutable in place
 * via reschedule() — this is not an append-only ledger.
 */
#[ORM\Entity(repositoryClass: TeamMemberNonParticipationPeriodRepository::class)]
#[ORM\Table(name: 'team_member_non_participation_periods')]
#[ORM\UniqueConstraint(name: 'uniq_non_participation_periods_stable_id', columns: ['stable_id'])]
#[ORM\Index(columns: ['team_member_id', 'starts_at', 'ends_at'], name: 'idx_non_participation_periods_member_range')]
class TeamMemberNonParticipationPeriod
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: 'uuid')]
    private Uuid $stableId;

    #[ORM\ManyToOne(targetEntity: PlanningTeamMember::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private PlanningTeamMember $teamMember;

    #[ORM\Column(type: 'datetimetz_immutable')]
    private \DateTimeImmutable $startsAt;

    #[ORM\Column(type: 'datetimetz_immutable')]
    private \DateTimeImmutable $endsAt;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(PlanningTeamMember $teamMember, \DateTimeImmutable $startsAt, \DateTimeImmutable $endsAt)
    {
        if ($endsAt <= $startsAt) {
            throw new \InvalidArgumentException('endsAt must be strictly after startsAt.');
        }

        $this->stableId = Uuid::v7();
        $this->teamMember = $teamMember;
        $this->startsAt = $startsAt;
        $this->endsAt = $endsAt;
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

    public function getTeamMember(): PlanningTeamMember
    {
        return $this->teamMember;
    }

    public function getStartsAt(): \DateTimeImmutable
    {
        return $this->startsAt;
    }

    public function getEndsAt(): \DateTimeImmutable
    {
        return $this->endsAt;
    }

    public function reschedule(\DateTimeImmutable $startsAt, \DateTimeImmutable $endsAt): void
    {
        if ($endsAt <= $startsAt) {
            throw new \InvalidArgumentException('endsAt must be strictly after startsAt.');
        }

        $this->startsAt = $startsAt;
        $this->endsAt = $endsAt;
        $this->touch();
    }

    /**
     * Touching periods (one's end equals the other's start) count as
     * conflicting too — see UserAvailabilityPeriod::overlapsOrTouches()
     * for the same policy applied to the personal calendar.
     */
    public function overlapsOrTouches(self $other): bool
    {
        return $this->startsAt <= $other->endsAt && $other->startsAt <= $this->endsAt;
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
