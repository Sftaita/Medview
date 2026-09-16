<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\TeamMemberParticipationPeriodRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * One segment of a TeamMember's participationFactor timeline
 * (docs/allocation-algorithm.md §20, D035) — never a single mutable field
 * on TeamMember. A change in factor closes the currently open segment and
 * appends a new one; past segments are never edited, so
 * participationFactorAt(date) always reads the value that was actually in
 * force at that date, regardless of what changed since.
 *
 * $validFrom is inclusive, $validTo is EXCLUSIVE ("valid up to but not
 * including this date") — null means open-ended. Two consecutive segments
 * abut exactly: segment N's $validTo equals segment N+1's $validFrom, with
 * no gap and no overlap. See docs/planning-domain.md.
 *
 * Genuinely immutable once created except for the one-time close() that
 * caps an open segment — there are no setters for $validFrom,
 * $participationFactor or $teamMember.
 */
#[ORM\Entity(repositoryClass: TeamMemberParticipationPeriodRepository::class)]
#[ORM\Table(name: 'team_member_participation_periods')]
#[ORM\Index(columns: ['team_member_id'], name: 'idx_participation_periods_team_member_id')]
class TeamMemberParticipationPeriod
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: TeamMember::class, inversedBy: 'participationPeriods')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private TeamMember $teamMember;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $validFrom;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $validTo = null;

    /**
     * Stored as an exact decimal (never a binary float, per
     * docs/decisions.md) — PostgreSQL NUMERIC, hydrated as a PHP string.
     * Use toFloat() for the arithmetic the future fairness engine needs;
     * a single multiplication per duty does not accumulate meaningful
     * float error at this precision.
     */
    #[ORM\Column(type: 'decimal', precision: 6, scale: 4)]
    private string $participationFactor;

    #[ORM\Column(length: 40, enumType: ParticipationFactorChangeReason::class)]
    private ParticipationFactorChangeReason $changeReason;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        TeamMember $teamMember,
        \DateTimeImmutable $validFrom,
        float $participationFactor,
        ParticipationFactorChangeReason $changeReason,
    ) {
        if ($participationFactor <= 0) {
            throw new \InvalidArgumentException('participationFactor must be strictly positive.');
        }

        $this->teamMember = $teamMember;
        $this->validFrom = $validFrom;
        $this->participationFactor = number_format($participationFactor, 4, '.', '');
        $this->changeReason = $changeReason;
        $this->createdAt = new \DateTimeImmutable();
        $teamMember->addParticipationPeriod($this);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTeamMember(): TeamMember
    {
        return $this->teamMember;
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

    public function isOpen(): bool
    {
        return null === $this->validTo;
    }

    /**
     * True when $date falls in [$validFrom, $validTo) — the sole predicate
     * behind participationFactorAt().
     */
    public function covers(\DateTimeImmutable $date): bool
    {
        if ($date < $this->validFrom) {
            return false;
        }

        return null === $this->validTo || $date < $this->validTo;
    }

    /**
     * Caps an open segment. One-way: a closed segment can never be
     * reopened or moved — that would rewrite history the engine may
     * already have used (docs/allocation-algorithm.md §20).
     *
     * @throws \LogicException if already closed
     */
    public function close(\DateTimeImmutable $validTo): void
    {
        if (null !== $this->validTo) {
            throw new \LogicException('This participation period is already closed.');
        }

        if ($validTo <= $this->validFrom) {
            throw new \InvalidArgumentException('validTo must be strictly after validFrom.');
        }

        $this->validTo = $validTo;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
