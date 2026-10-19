<?php

declare(strict_types=1);

namespace App\Entity;

use App\Exception\MultiplePeriodsNotSupportedException;
use App\Exception\PlanningLineWithoutPeriodException;
use App\Repository\PlanningLineRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * One "ligne de garde" inside a Planning — e.g. "Garde principale" (Team
 * Seniors) or "Renfort" (Team Fellows). Each line runs its own mono-team
 * engine underneath via its PlanningPeriods (one per cycle of the Planning
 * it takes part in): FairnessPeriod, Duty, DutyType, PlanningRuleSet,
 * snapshot, eligibility all stay exactly as scoped to one PlanningTeam as
 * they were before Planning existed (docs/planning.md §4) — this entity is
 * purely the aggregator's bookkeeping, never itself read by
 * PlanningSnapshotService/EligibilityService.
 *
 * $planningTeam and $planningLine are deliberately two separate entities
 * even though v1 enforces a strict 1:1 relationship (unique on
 * $planningTeam — see migrations): a PlanningTeam is only ever created
 * by, and immediately consumed by, exactly one PlanningLine
 * (PlanningLineService::addLine(), docs/decisions.md D079). Keeping them
 * distinct is what lets a future version put more than one line on the
 * same PlanningTeam without a domain rewrite (docs/decisions.md D073,
 * still valid under the new model).
 */
#[ORM\Entity(repositoryClass: PlanningLineRepository::class)]
#[ORM\Table(name: 'planning_lines')]
#[ORM\UniqueConstraint(name: 'uniq_planning_lines_stable_id', columns: ['stable_id'])]
#[ORM\UniqueConstraint(name: 'uniq_planning_lines_planning_team', columns: ['planning_team_id'])]
#[ORM\Index(columns: ['planning_id'], name: 'idx_planning_lines_planning_id')]
class PlanningLine
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: 'uuid')]
    private Uuid $stableId;

    #[ORM\ManyToOne(targetEntity: Planning::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Planning $planning;

    #[ORM\ManyToOne(targetEntity: PlanningTeam::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private PlanningTeam $planningTeam;

    /**
     * Inverse side only: a line has one PlanningPeriod per cycle it takes
     * part in, each pointing at it (planning_periods.planning_line_id —
     * docs/planning-cycles.md §3.4, docs/decisions.md D187). None at all for
     * a line added while no period could take it (§5.5).
     *
     * @var Collection<int, PlanningPeriod>
     */
    #[ORM\OneToMany(targetEntity: PlanningPeriod::class, mappedBy: 'line')]
    private Collection $periods;

    #[ORM\Column(length: 150)]
    private string $name;

    #[ORM\Column(length: 20, enumType: PlanningLineType::class)]
    private PlanningLineType $type;

    #[ORM\Column]
    private int $position;

    #[ORM\Column]
    private bool $active = true;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    /**
     * $firstPeriod: the line's period in the cycle it starts in, already
     * attached to that cycle — or null when no cycle can take it yet
     * (docs/planning-cycles.md §5.5): it gets one in the next cycle created.
     */
    public function __construct(
        Planning $planning,
        PlanningTeam $planningTeam,
        ?PlanningPeriod $firstPeriod,
        string $name,
        PlanningLineType $type,
        int $position,
    ) {
        if ($planningTeam->getPlanning() !== $planning) {
            throw new \InvalidArgumentException('A PlanningLine must use a PlanningTeam from the same Planning.');
        }

        if (null !== $firstPeriod && $firstPeriod->getTeam() !== $planningTeam) {
            throw new \InvalidArgumentException('A PlanningLine must use a PlanningPeriod from the same PlanningTeam.');
        }

        if (null !== $firstPeriod && $firstPeriod->getCycle()?->getPlanning() !== $planning) {
            throw new \InvalidArgumentException('A PlanningLine\'s PlanningPeriod must be attached to a cycle of its Planning (docs/planning-cycles.md I3).');
        }

        $this->stableId = Uuid::v7();
        $this->planning = $planning;
        $this->planningTeam = $planningTeam;
        $this->periods = new ArrayCollection();
        $this->name = $name;
        $this->type = $type;
        $this->position = $position;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
        $firstPeriod?->bindToLine($this);
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

    public function getPlanningTeam(): PlanningTeam
    {
        return $this->planningTeam;
    }

    /**
     * @return list<PlanningPeriod> ordered by cycle number
     */
    public function getPeriods(): array
    {
        $periods = $this->periods->toArray();
        usort($periods, static fn (PlanningPeriod $a, PlanningPeriod $b): int => ($a->getCycle()?->getNumber() ?? 0) <=> ($b->getCycle()?->getNumber() ?? 0));

        return array_values($periods);
    }

    /** This line's period in $cycle, or null when it does not take part in it. */
    public function periodIn(PlanningCycle $cycle): ?PlanningPeriod
    {
        foreach ($this->periods as $period) {
            if ($period->getCycle() === $cycle) {
                return $period;
            }
        }

        return null;
    }

    /**
     * "The" period of this line, for the flows that are not period-aware yet
     * (lots L3/L4, docs/decisions.md D187): only meaningful while the
     * Planning has a single cycle — refused otherwise, never guessed. Null
     * when the line has no period (added while no period could take it).
     *
     * @throws MultiplePeriodsNotSupportedException
     */
    public function getSingleCyclePeriod(): ?PlanningPeriod
    {
        $this->planning->assertSingleCycle();

        return $this->periods->first() ?: null;
    }

    /**
     * Same as getSingleCyclePeriod(), for a flow that needs the line to have
     * its period.
     *
     * @throws MultiplePeriodsNotSupportedException
     * @throws PlanningLineWithoutPeriodException
     */
    public function requireSingleCyclePeriod(): PlanningPeriod
    {
        return $this->getSingleCyclePeriod() ?? throw new PlanningLineWithoutPeriodException();
    }

    /** @internal called by PlanningPeriod::bindToLine() */
    public function registerPeriod(PlanningPeriod $period): void
    {
        if (!$this->periods->contains($period)) {
            $this->periods->add($period);
        }
    }

    /** @internal called when a period without history is deleted with its cycle or its line, or detached from its deleted line (D188) */
    public function unregisterPeriod(PlanningPeriod $period): void
    {
        $this->periods->removeElement($period);
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function rename(string $name): void
    {
        $this->name = $name;
        $this->touch();
    }

    public function getType(): PlanningLineType
    {
        return $this->type;
    }

    public function isPrimary(): bool
    {
        return PlanningLineType::PRIMARY === $this->type;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): void
    {
        $this->active = $active;
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
