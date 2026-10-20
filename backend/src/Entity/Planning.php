<?php

declare(strict_types=1);

namespace App\Entity;

use App\Exception\MultiplePeriodsNotSupportedException;
use App\Repository\PlanningRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * The user-visible planning aggregate (docs/planning.md) — e.g. "Gardes
 * Orthopédie Octobre 2026". Groups one or more PlanningLine, each owning
 * its own PlanningTeam and running its own mono-team engine underneath
 * (PlanningPeriod → FairnessPeriod / Duties / Rules / Generations,
 * unchanged since Lot 3). A PlanningTeam is never shared between two
 * Plannings, and never referenced by a client when creating a line — it is
 * always created inline by PlanningLineService (docs/decisions.md D079).
 *
 * $creator is the sole manager in v1 (docs/decisions.md D071) — an
 * OWNER/ADMIN of one of this Planning's PlanningTeams gets no management
 * right over the Planning merely from that team role. No
 * collaborator/co-owner concept exists yet.
 *
 * $startsAt/$endsAt are the envelope of its cycles (docs/planning-cycles.md
 * I5): first start, last end — changed only together with a cycle, in the
 * same transaction.
 */
#[ORM\Entity(repositoryClass: PlanningRepository::class)]
#[ORM\Table(name: 'plannings')]
#[ORM\UniqueConstraint(name: 'uniq_plannings_stable_id', columns: ['stable_id'])]
#[ORM\Index(columns: ['creator_id'], name: 'idx_plannings_creator_id')]
class Planning
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: 'uuid')]
    private Uuid $stableId;

    #[ORM\Column(length: 150)]
    private string $name;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private User $creator;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $startsAt;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $endsAt;

    #[ORM\Column(length: 64)]
    private string $timezone;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    /**
     * Inverse side only (no column): kept in sync by PlanningCycle's
     * constructor and PlanningCycleService::deleteLast().
     *
     * @var Collection<int, PlanningCycle>
     */
    #[ORM\OneToMany(targetEntity: PlanningCycle::class, mappedBy: 'planning')]
    #[ORM\OrderBy(['number' => 'ASC'])]
    private Collection $cycles;

    public function __construct(
        string $name,
        User $creator,
        \DateTimeImmutable $startsAt,
        \DateTimeImmutable $endsAt,
        string $timezone,
    ) {
        if ($endsAt <= $startsAt) {
            throw new \InvalidArgumentException('endsAt must be strictly after startsAt.');
        }

        $this->stableId = Uuid::v7();
        $this->name = $name;
        $this->creator = $creator;
        $this->startsAt = $startsAt;
        $this->endsAt = $endsAt;
        $this->timezone = $timezone;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
        $this->cycles = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getStableId(): Uuid
    {
        return $this->stableId;
    }

    public function getName(): string
    {
        return $this->name;
    }

    /**
     * The only field a PATCH may change in v1 — changing $startsAt/$endsAt
     * would require cascading through every PlanningLine's PlanningPeriod
     * (docs/planning.md §5) and re-validating each Team's FairnessPeriod,
     * deliberately not attempted here (docs/decisions.md D077).
     */
    public function rename(string $name): void
    {
        $this->name = $name;
        $this->touch();
    }

    /**
     * Grows the range, never shrinks it (docs/availability-collection.md §5,
     * docs/decisions.md D122): every date already planned stays planned.
     * The caller — PlanningExtensionService — is responsible for cascading
     * the same change to each PlanningLine's periods (D075) in the same
     * transaction.
     */
    public function extendTo(\DateTimeImmutable $startsAt, \DateTimeImmutable $endsAt): void
    {
        if ($startsAt > $this->startsAt || $endsAt < $this->endsAt) {
            throw new \InvalidArgumentException('A planning can only be extended: the new range must contain the current one.');
        }

        $this->startsAt = $startsAt;
        $this->endsAt = $endsAt;
        $this->touch();
    }

    /**
     * Moves the envelope to [$startsAt, $endsAt) — only ever called by
     * PlanningCycleService, together with the cycle that changes (creation,
     * new end, deletion of the last cycle).
     */
    public function changeEnvelope(\DateTimeImmutable $startsAt, \DateTimeImmutable $endsAt): void
    {
        if ($endsAt <= $startsAt) {
            throw new \InvalidArgumentException('endsAt must be strictly after startsAt.');
        }

        $this->startsAt = $startsAt;
        $this->endsAt = $endsAt;
        $this->touch();
    }

    /**
     * @return list<PlanningCycle> ordered by number
     */
    public function getCycles(): array
    {
        $cycles = $this->cycles->toArray();
        usort($cycles, static fn (PlanningCycle $a, PlanningCycle $b): int => $a->getNumber() <=> $b->getNumber());

        return array_values($cycles);
    }

    public function hasSeveralCycles(): bool
    {
        return $this->cycles->count() > 1;
    }

    /**
     * The guard of every flow not yet made period-aware (docs/planning-cycles.md
     * lots L3/L4, docs/decisions.md D187): generation, completion, results,
     * publication, reassignment, swaps, exports… They read "the" period of a
     * line, which only exists while the Planning has a single cycle. With
     * several cycles they refuse (409) instead of guessing one.
     *
     * @throws MultiplePeriodsNotSupportedException
     */
    public function assertSingleCycle(): void
    {
        if ($this->hasSeveralCycles()) {
            throw new MultiplePeriodsNotSupportedException();
        }
    }

    /**
     * The cycle of a single-cycle Planning, for the flows that are not
     * period-aware yet (same guard as assertSingleCycle()).
     *
     * @throws MultiplePeriodsNotSupportedException
     */
    public function getOnlyCycle(): PlanningCycle
    {
        $this->assertSingleCycle();

        return $this->cycles->first() ?: throw new \LogicException('Every Planning has its cycle 1 (docs/planning-cycles.md I1).');
    }

    /** @internal called by PlanningCycle's constructor */
    public function registerCycle(PlanningCycle $cycle): void
    {
        if (!$this->cycles->contains($cycle)) {
            $this->cycles->add($cycle);
        }
    }

    /** @internal called by PlanningCycleService::deleteLast() */
    public function unregisterCycle(PlanningCycle $cycle): void
    {
        $this->cycles->removeElement($cycle);
    }

    public function getCreator(): User
    {
        return $this->creator;
    }

    public function getStartsAt(): \DateTimeImmutable
    {
        return $this->startsAt;
    }

    public function getEndsAt(): \DateTimeImmutable
    {
        return $this->endsAt;
    }

    public function getTimezone(): string
    {
        return $this->timezone;
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
