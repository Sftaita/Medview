<?php

declare(strict_types=1);

namespace App\Entity;

use App\Exception\InvalidPlanningPeriodTransitionException;
use App\Exception\PlanningPeriodLockedException;
use App\Repository\PlanningPeriodRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * An operational sub-period of a FairnessPeriod in which duties are
 * actually generated (docs/allocation-algorithm.md §4/§18) — e.g. one of
 * three four-month slices of a calendar-year FairnessPeriod.
 *
 * $stableId never changes across regenerations (a regeneration mutates
 * this row's status/content, it never replaces it) — the future tie-break
 * seedMaterial depends on that (docs/allocation-algorithm.md §13).
 *
 * Team consistency with $fairnessPeriod is checked here for a friendly
 * error, and enforced unconditionally by a composite foreign key at the
 * database level (see migrations) — the application check is a courtesy,
 * not the actual guarantee.
 */
#[ORM\Entity(repositoryClass: PlanningPeriodRepository::class)]
#[ORM\Table(name: 'planning_periods')]
#[ORM\UniqueConstraint(name: 'uniq_planning_periods_stable_id', columns: ['stable_id'])]
#[ORM\UniqueConstraint(name: 'uniq_planning_periods_id_team_id', columns: ['id', 'team_id'])]
#[ORM\Index(columns: ['team_id'], name: 'idx_planning_periods_team_id')]
#[ORM\Index(columns: ['fairness_period_id'], name: 'idx_planning_periods_fairness_period_id')]
class PlanningPeriod
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: 'uuid')]
    private Uuid $stableId;

    #[ORM\ManyToOne(targetEntity: PlanningTeam::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private PlanningTeam $team;

    #[ORM\ManyToOne(targetEntity: FairnessPeriod::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private FairnessPeriod $fairnessPeriod;

    #[ORM\Column(length: 150)]
    private string $name;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $startsAt;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $endsAt;

    #[ORM\Column(length: 20, enumType: PlanningPeriodStatus::class)]
    private PlanningPeriodStatus $status;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        PlanningTeam $team,
        FairnessPeriod $fairnessPeriod,
        string $name,
        \DateTimeImmutable $startsAt,
        \DateTimeImmutable $endsAt,
    ) {
        if ($endsAt <= $startsAt) {
            throw new \InvalidArgumentException('endsAt must be strictly after startsAt.');
        }

        if ($fairnessPeriod->getTeam() !== $team) {
            throw new \InvalidArgumentException('A PlanningPeriod must belong to the same PlanningTeam as its FairnessPeriod.');
        }

        if ($startsAt < $fairnessPeriod->getStartsAt() || $endsAt > $fairnessPeriod->getEndsAt()) {
            throw new \InvalidArgumentException('A PlanningPeriod must fall entirely within its FairnessPeriod.');
        }

        $this->stableId = Uuid::v7();
        $this->team = $team;
        $this->fairnessPeriod = $fairnessPeriod;
        $this->name = $name;
        $this->startsAt = $startsAt;
        $this->endsAt = $endsAt;
        $this->status = PlanningPeriodStatus::DRAFT;
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

    public function getTeam(): PlanningTeam
    {
        return $this->team;
    }

    public function getFairnessPeriod(): FairnessPeriod
    {
        return $this->fairnessPeriod;
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

    public function getStatus(): PlanningPeriodStatus
    {
        return $this->status;
    }

    /**
     * The only way this status ever changes. See
     * PlanningPeriodStatus::canTransitionTo() for the allowed graph and
     * PlanningPeriodLifecycleService for the orchestration around it.
     *
     * @throws InvalidPlanningPeriodTransitionException
     */
    public function transitionTo(PlanningPeriodStatus $target): void
    {
        if (!$this->status->canTransitionTo($target)) {
            throw new InvalidPlanningPeriodTransitionException($this->status, $target);
        }

        $this->status = $target;
        $this->touch();
    }

    /**
     * Grows this period in place when its Planning is extended
     * (docs/decisions.md D122). Refused once the period is VALIDATED,
     * PUBLISHED or ARCHIVED: a published period is never edited
     * (docs/allocation-algorithm.md §18) and the engine cannot yet generate
     * an additional slice next to an already validated one.
     *
     * @throws PlanningPeriodLockedException
     */
    public function extendTo(\DateTimeImmutable $startsAt, \DateTimeImmutable $endsAt): void
    {
        if ($startsAt > $this->startsAt || $endsAt < $this->endsAt) {
            throw new \InvalidArgumentException('A PlanningPeriod can only be extended: the new range must contain the current one.');
        }
        if (!\in_array($this->status, [PlanningPeriodStatus::DRAFT, PlanningPeriodStatus::GENERATED], true)) {
            throw new PlanningPeriodLockedException();
        }
        if ($startsAt < $this->fairnessPeriod->getStartsAt() || $endsAt > $this->fairnessPeriod->getEndsAt()) {
            throw new \InvalidArgumentException('Extend the FairnessPeriod before the PlanningPeriod: a PlanningPeriod must fall entirely within its FairnessPeriod.');
        }

        $this->startsAt = $startsAt;
        $this->endsAt = $endsAt;
        $this->touch();
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
