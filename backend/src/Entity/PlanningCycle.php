<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PlanningCycleRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * One "période" of a Planning (docs/planning-cycles.md): a date range shared
 * by every line, holding one PlanningPeriod per line. Cycles of a Planning
 * are numbered 1, 2, 3… and are contiguous and non-overlapping; the
 * database enforces the non-overlap and the numbering
 * (excl_planning_cycles_no_overlap, uniq_planning_cycles_planning_number),
 * the services the contiguity.
 *
 * Cycle 1 is created with the Planning (docs/decisions.md D186); the
 * following ones by PlanningCycleService::createNext(), behind
 * MEDVUE_PERIOD_CREATION_ENABLED (docs/decisions.md D187).
 *
 * $endsAt is exclusive, like every date range of the domain. No status is
 * stored: a cycle's state is derived from its PlanningPeriods and
 * collections (docs/planning-cycles.md §4.2).
 */
#[ORM\Entity(repositoryClass: PlanningCycleRepository::class)]
#[ORM\Table(name: 'planning_cycles')]
#[ORM\UniqueConstraint(name: 'uniq_planning_cycles_stable_id', columns: ['stable_id'])]
#[ORM\UniqueConstraint(name: 'uniq_planning_cycles_planning_number', columns: ['planning_id', 'number'])]
#[ORM\UniqueConstraint(name: 'uniq_planning_cycles_id_planning_id', columns: ['id', 'planning_id'])]
class PlanningCycle
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: 'uuid')]
    private Uuid $stableId;

    #[ORM\ManyToOne(targetEntity: Planning::class, inversedBy: 'cycles')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Planning $planning;

    #[ORM\Column(type: 'smallint')]
    private int $number;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $startsAt;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $endsAt;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /** Null only for the cycles 1 created by the migration of existing plannings. */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'RESTRICT')]
    private ?User $createdBy;

    public function __construct(
        Planning $planning,
        int $number,
        \DateTimeImmutable $startsAt,
        \DateTimeImmutable $endsAt,
        ?User $createdBy,
    ) {
        if ($number < 1) {
            throw new \InvalidArgumentException('A PlanningCycle number starts at 1.');
        }
        if ($endsAt <= $startsAt) {
            throw new \InvalidArgumentException('endsAt must be strictly after startsAt.');
        }

        $this->stableId = Uuid::v7();
        $this->planning = $planning;
        $this->number = $number;
        $this->startsAt = $startsAt;
        $this->endsAt = $endsAt;
        $this->createdAt = new \DateTimeImmutable();
        $this->createdBy = $createdBy;
        $planning->registerCycle($this);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getStableId(): Uuid
    {
        return $this->stableId;
    }

    public function getPlanning(): Planning
    {
        return $this->planning;
    }

    public function getNumber(): int
    {
        return $this->number;
    }

    public function getStartsAt(): \DateTimeImmutable
    {
        return $this->startsAt;
    }

    public function getEndsAt(): \DateTimeImmutable
    {
        return $this->endsAt;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    public function hasSameDatesAs(\DateTimeImmutable $startsAt, \DateTimeImmutable $endsAt): bool
    {
        return $this->startsAt->format('Y-m-d') === $startsAt->format('Y-m-d')
            && $this->endsAt->format('Y-m-d') === $endsAt->format('Y-m-d');
    }

    /** Whether [$startsAt, $endsAt) lies inside this cycle. */
    public function contains(\DateTimeImmutable $startsAt, \DateTimeImmutable $endsAt): bool
    {
        return $startsAt->format('Y-m-d') >= $this->startsAt->format('Y-m-d')
            && $endsAt->format('Y-m-d') <= $this->endsAt->format('Y-m-d');
    }

    /**
     * The new end of the last cycle (docs/planning-cycles.md §5.2): later or
     * earlier, its start never moves. Every rule deciding whether the data
     * allow it lives in PlanningCycleService::changeEnd().
     */
    public function changeEnd(\DateTimeImmutable $endsAt): void
    {
        if ($endsAt <= $this->startsAt) {
            throw new \InvalidArgumentException('endsAt must be strictly after startsAt.');
        }
        $this->endsAt = $endsAt;
    }

    /** Whether $date (a calendar day) falls inside this cycle. */
    public function containsDay(\DateTimeImmutable $date): bool
    {
        $day = $date->format('Y-m-d');

        return $day >= $this->startsAt->format('Y-m-d') && $day < $this->endsAt->format('Y-m-d');
    }

    /**
     * Grows this cycle in place, together with its Planning and periods, when
     * a single-cycle Planning is extended (docs/decisions.md D122, kept by
     * docs/planning-cycles.md §5.2 for that case only): the caller keeps the
     * cycle's dates equal to the Planning's.
     */
    public function extendTo(\DateTimeImmutable $startsAt, \DateTimeImmutable $endsAt): void
    {
        if ($startsAt > $this->startsAt || $endsAt < $this->endsAt) {
            throw new \InvalidArgumentException('A PlanningCycle can only be extended: the new range must contain the current one.');
        }

        $this->startsAt = $startsAt;
        $this->endsAt = $endsAt;
    }
}
