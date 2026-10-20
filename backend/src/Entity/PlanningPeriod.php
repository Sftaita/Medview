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
#[ORM\Index(columns: ['planning_cycle_id'], name: 'idx_planning_periods_planning_cycle_id')]
#[ORM\UniqueConstraint(name: 'uniq_planning_periods_line_cycle', columns: ['planning_line_id', 'planning_cycle_id'])]
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

    /**
     * The "période" of the Planning this line period belongs to
     * (docs/planning-cycles.md). Never null for the period of a line
     * (chk_planning_periods_cycle_of_line, docs/decisions.md D187); null only
     * for an orphan period left behind by a line deleted before lot L2 whose
     * dates match no cycle — kept as it is.
     */
    #[ORM\ManyToOne(targetEntity: PlanningCycle::class)]
    #[ORM\JoinColumn(name: 'planning_cycle_id', nullable: true, onDelete: 'RESTRICT')]
    private ?PlanningCycle $cycle = null;

    /**
     * The line this period belongs to — the only link between them since
     * lot L2 (a line has one period per cycle it takes part in). Null only
     * for the orphan periods of lines deleted before L2; a line is now
     * deleted with its periods, or not at all (RESTRICT).
     */
    #[ORM\ManyToOne(targetEntity: PlanningLine::class, inversedBy: 'periods')]
    #[ORM\JoinColumn(name: 'planning_line_id', nullable: true, onDelete: 'RESTRICT')]
    private ?PlanningLine $line = null;

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

    public function getCycle(): ?PlanningCycle
    {
        return $this->cycle;
    }

    /**
     * Attaches this period to its cycle, once. The cycle must be of this
     * period's Planning and have exactly its dates (docs/planning-cycles.md
     * I3); both are re-checked by app:planning-cycles:audit.
     */
    public function attachToCycle(PlanningCycle $cycle): void
    {
        if (null !== $this->cycle) {
            if ($this->cycle === $cycle) {
                return;
            }
            throw new \LogicException('A PlanningPeriod never changes cycle.');
        }
        if ($cycle->getPlanning() !== $this->team->getPlanning()) {
            throw new \InvalidArgumentException('A PlanningPeriod must belong to a cycle of its own Planning.');
        }
        if (!$cycle->hasSameDatesAs($this->startsAt, $this->endsAt)) {
            throw new \InvalidArgumentException('A PlanningPeriod must have exactly its cycle\'s dates (docs/planning-cycles.md I3).');
        }

        $this->cycle = $cycle;
        $this->touch();
    }

    public function getLine(): ?PlanningLine
    {
        return $this->line;
    }

    /**
     * Gives this period to its line, once (the PlanningLine constructor for
     * its first period, PlanningCycleService for the following ones).
     */
    public function bindToLine(PlanningLine $line): void
    {
        if (null !== $this->line && $this->line !== $line) {
            throw new \LogicException('A PlanningPeriod belongs to a single PlanningLine.');
        }
        if ($line->getPlanningTeam() !== $this->team) {
            throw new \InvalidArgumentException('A PlanningPeriod must belong to a line of its own PlanningTeam.');
        }

        $this->line = $line;
        $line->registerPeriod($this);
    }

    /**
     * Its line is deleted while the period keeps its history (docs/decisions.md
     * D188): the period stays, in its cycle, attached to no line — as a line
     * deletion always left it before periods existed.
     */
    public function detachFromLine(): void
    {
        $this->line?->unregisterPeriod($this);
        $this->line = null;
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
        if (null !== $this->cycle && !$this->cycle->hasSameDatesAs($startsAt, $endsAt)) {
            throw new \InvalidArgumentException('Extend the PlanningCycle before its PlanningPeriods: a period keeps exactly its cycle\'s dates.');
        }

        $this->startsAt = $startsAt;
        $this->endsAt = $endsAt;
        $this->touch();
    }

    /**
     * The new end of a period of the last cycle (docs/planning-cycles.md
     * §5.2), later or earlier: after its cycle's and before its
     * FairnessPeriod's when it grows. A VALIDATED/PUBLISHED/ARCHIVED period
     * never moves (I8).
     *
     * @throws PlanningPeriodLockedException
     */
    public function changeEnd(\DateTimeImmutable $endsAt): void
    {
        if (!\in_array($this->status, [PlanningPeriodStatus::DRAFT, PlanningPeriodStatus::GENERATED], true)) {
            throw new PlanningPeriodLockedException();
        }
        if (null === $this->cycle || !$this->cycle->hasSameDatesAs($this->startsAt, $endsAt)) {
            throw new \InvalidArgumentException('Change the PlanningCycle first: a period keeps exactly its cycle\'s dates.');
        }
        if ($endsAt > $this->fairnessPeriod->getEndsAt()) {
            throw new \InvalidArgumentException('Extend the FairnessPeriod before the PlanningPeriod: a PlanningPeriod must fall entirely within its FairnessPeriod.');
        }

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
