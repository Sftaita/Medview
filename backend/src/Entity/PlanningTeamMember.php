<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PlanningTeamMemberRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * One membership *stint* of a User in a PlanningTeam — not a nullable
 * pointer on User (a user belongs to several teams, possibly across
 * several Plannings) and not a bare join table (it carries role, dates
 * and history, see CLAUDE.md/D012).
 *
 * $planning is a deliberate denormalization of $planningTeam->getPlanning()
 * (docs/decisions.md D079/D080): the "at most one open membership per
 * (Planning, User)" invariant needs to be a DB constraint on this table
 * directly, but $planning is only reachable through $planningTeam in a
 * naive model, which a CHECK/UNIQUE constraint cannot traverse. A
 * composite foreign key (planning_team_id, planning_id) referencing
 * planning_teams(id, planning_id) — same technique as D051 — guarantees
 * $planning can never drift from $planningTeam->getPlanning() even though
 * it is stored redundantly; it is hand-added in the migration and is not
 * representable in the ORM mapping (see the composite-FK pruning note in
 * docs/decisions.md).
 *
 * Leaving a team never deletes this row (CLAUDE.md: no destructive
 * removal of anything with history) — it sets $membershipEnd. Rejoining
 * later creates a brand new PlanningTeamMember row rather than reopening
 * this one, so the membership history stays a true append-only record.
 * At most one row per (planning, user) may have a null $membershipEnd at
 * a time — enforced by a partial unique index on (planning_id, user_id),
 * see migrations and docs/decisions.md D080. This replaces the earlier,
 * now-abandoned global "one open membership in the whole app" rule
 * (docs/decisions.md D072, marked replaced).
 *
 * $role is intentionally never folded into User::getRoles() (D012):
 * "who can do what in this specific team" is a Voter's job, not a claim
 * about the user's identity in general.
 */
#[ORM\Entity(repositoryClass: PlanningTeamMemberRepository::class)]
#[ORM\Table(name: 'planning_team_members')]
#[ORM\UniqueConstraint(name: 'uniq_planning_team_members_stable_id', columns: ['stable_id'])]
#[ORM\Index(columns: ['planning_team_id'], name: 'idx_planning_team_members_planning_team_id')]
#[ORM\Index(columns: ['planning_id'], name: 'idx_planning_team_members_planning_id')]
#[ORM\Index(columns: ['user_id'], name: 'idx_planning_team_members_user_id')]
class PlanningTeamMember
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: 'uuid')]
    private Uuid $stableId;

    #[ORM\ManyToOne(targetEntity: PlanningTeam::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private PlanningTeam $planningTeam;

    /**
     * Denormalized from $planningTeam->getPlanning() — see class docblock.
     */
    #[ORM\ManyToOne(targetEntity: Planning::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Planning $planning;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private User $user;

    #[ORM\Column(length: 20, enumType: TeamMemberRole::class)]
    private TeamMemberRole $role;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $membershipStart;

    /**
     * Null while the membership is open. Once set, it is never unset —
     * see the class docblock.
     */
    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $membershipEnd = null;

    /**
     * @var Collection<int, TeamMemberParticipationPeriod>
     */
    #[ORM\OneToMany(targetEntity: TeamMemberParticipationPeriod::class, mappedBy: 'teamMember')]
    private Collection $participationPeriods;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(PlanningTeam $planningTeam, User $user, TeamMemberRole $role, \DateTimeImmutable $membershipStart)
    {
        $this->stableId = Uuid::v7();
        $this->planningTeam = $planningTeam;
        $this->planning = $planningTeam->getPlanning();
        $this->user = $user;
        $this->role = $role;
        $this->membershipStart = $membershipStart;
        $this->participationPeriods = new ArrayCollection();
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

    public function getPlanningTeam(): PlanningTeam
    {
        return $this->planningTeam;
    }

    public function getPlanning(): Planning
    {
        return $this->planning;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getRole(): TeamMemberRole
    {
        return $this->role;
    }

    public function changeRole(TeamMemberRole $role): void
    {
        $this->role = $role;
        $this->touch();
    }

    public function getMembershipStart(): \DateTimeImmutable
    {
        return $this->membershipStart;
    }

    public function getMembershipEnd(): ?\DateTimeImmutable
    {
        return $this->membershipEnd;
    }

    /**
     * True as long as no end date has been recorded yet — the invariant
     * the database's partial unique index also enforces. Does not by
     * itself mean "active today" if $membershipStart is in the future;
     * use isActiveAt() for a date-aware check.
     */
    public function isCurrentlyOpen(): bool
    {
        return null === $this->membershipEnd;
    }

    /**
     * Date-aware membership check used by the future engine's
     * structuralOpportunity(user, duty) — docs/allocation-algorithm.md §5.
     */
    public function isActiveAt(\DateTimeImmutable $date): bool
    {
        if ($date < $this->membershipStart) {
            return false;
        }

        return null === $this->membershipEnd || $date < $this->membershipEnd;
    }

    /**
     * Ends this membership stint. Never call this twice — the record is
     * one-way closed, matching the "history is never destructively
     * rewritten" rule.
     *
     * @throws \LogicException if already closed
     */
    public function close(\DateTimeImmutable $membershipEnd): void
    {
        if (null !== $this->membershipEnd) {
            throw new \LogicException('This membership is already closed.');
        }

        if ($membershipEnd < $this->membershipStart) {
            throw new \InvalidArgumentException('membershipEnd cannot precede membershipStart.');
        }

        $this->membershipEnd = $membershipEnd;
        $this->touch();
    }

    /**
     * @return Collection<int, TeamMemberParticipationPeriod>
     */
    public function getParticipationPeriods(): Collection
    {
        return $this->participationPeriods;
    }

    /**
     * Keeps the inverse side of the association in sync in-memory — called
     * from TeamMemberParticipationPeriod's constructor. Doctrine does not
     * populate an already-loaded inverse collection just because the
     * owning side's foreign key was set; without this, code that
     * constructs a period and immediately reads
     * $teamMember->getParticipationPeriods() within the same request would
     * see a stale, empty collection until the next fetch.
     *
     * @internal
     */
    public function addParticipationPeriod(TeamMemberParticipationPeriod $period): void
    {
        if (!$this->participationPeriods->contains($period)) {
            $this->participationPeriods->add($period);
        }
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
