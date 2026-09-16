<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\TeamMemberRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * One membership *stint* of a User in a Team — not a nullable pointer on
 * User (a user belongs to several teams) and not a bare join table (it
 * carries role, dates and history, see CLAUDE.md/D012).
 *
 * Leaving a team never deletes this row (CLAUDE.md: no destructive
 * removal of anything with history) — it sets $membershipEnd. Rejoining
 * later creates a brand new TeamMember row rather than reopening this one,
 * so the membership history stays a true append-only record. At most one
 * row per (team, user) may have a null $membershipEnd at a time — enforced
 * by a partial unique index, see migrations (docs/planning-domain.md).
 *
 * $role is intentionally never folded into User::getRoles() (D012):
 * "who can do what in this specific team" is a Voter's job, not a claim
 * about the user's identity in general.
 */
#[ORM\Entity(repositoryClass: TeamMemberRepository::class)]
#[ORM\Table(name: 'team_members')]
#[ORM\UniqueConstraint(name: 'uniq_team_members_stable_id', columns: ['stable_id'])]
#[ORM\Index(columns: ['team_id'], name: 'idx_team_members_team_id')]
#[ORM\Index(columns: ['user_id'], name: 'idx_team_members_user_id')]
class TeamMember
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * Added for the availability lot (docs/decisions.md D056), reversing
     * docs/planning-domain.md's original "no need identified yet" call —
     * the non-participation endpoints must address a TeamMember from a
     * public URL without ever exposing the auto-increment $id.
     */
    #[ORM\Column(type: 'uuid')]
    private Uuid $stableId;

    #[ORM\ManyToOne(targetEntity: Team::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Team $team;

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

    public function __construct(Team $team, User $user, TeamMemberRole $role, \DateTimeImmutable $membershipStart)
    {
        $this->stableId = Uuid::v7();
        $this->team = $team;
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

    public function getTeam(): Team
    {
        return $this->team;
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
