<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\FairnessPeriodRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * The accounting window equity is measured over (docs/allocation-algorithm.md
 * §4) — a calendar year by default, but team-defined. Deliberately holds
 * no carryOverPolicy field yet: named-holiday history already survives any
 * boundary via exponential decay regardless of FairnessPeriod (§7), and no
 * other carry-over rule is implemented until the fairness engine that
 * would actually read it exists — adding the column now would be a
 * half-built concept with nothing behind it.
 *
 * A PlanningTeam's FairnessPeriods never overlap in time — a duty must belong to
 * exactly one equity ledger, or requiredDemand/target computations become
 * ambiguous. Enforced at the database level (see migrations), not just in
 * application code.
 */
#[ORM\Entity(repositoryClass: FairnessPeriodRepository::class)]
#[ORM\Table(name: 'fairness_periods')]
#[ORM\Index(columns: ['team_id'], name: 'idx_fairness_periods_team_id')]
#[ORM\UniqueConstraint(name: 'uniq_fairness_periods_id_team_id', columns: ['id', 'team_id'])]
class FairnessPeriod
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: PlanningTeam::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private PlanningTeam $team;

    #[ORM\Column(length: 150)]
    private string $name;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $startsAt;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $endsAt;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(PlanningTeam $team, string $name, \DateTimeImmutable $startsAt, \DateTimeImmutable $endsAt)
    {
        if ($endsAt <= $startsAt) {
            throw new \InvalidArgumentException('endsAt must be strictly after startsAt.');
        }

        $this->team = $team;
        $this->name = $name;
        $this->startsAt = $startsAt;
        $this->endsAt = $endsAt;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTeam(): PlanningTeam
    {
        return $this->team;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getStartsAt(): \DateTimeImmutable
    {
        return $this->startsAt;
    }

    public function getEndsAt(): \DateTimeImmutable
    {
        return $this->endsAt;
    }

    /**
     * Grows this period in place when its Planning is extended
     * (docs/decisions.md D122) — never shrinks it. Overlap with the team's
     * other FairnessPeriods is the caller's concern (and the database's
     * exclusion constraint).
     */
    public function extendTo(\DateTimeImmutable $startsAt, \DateTimeImmutable $endsAt): void
    {
        if ($startsAt > $this->startsAt || $endsAt < $this->endsAt) {
            throw new \InvalidArgumentException('A FairnessPeriod can only be extended: the new range must contain the current one.');
        }

        $this->startsAt = $startsAt;
        $this->endsAt = $endsAt;
    }

    public function contains(\DateTimeImmutable $date): bool
    {
        return $date >= $this->startsAt && $date < $this->endsAt;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
