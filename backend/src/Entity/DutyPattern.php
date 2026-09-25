<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\DutyPatternRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * The *definition* of a group of linked duties (e.g. "Friday+Saturday+
 * Sunday", "24 Dec+25 Dec") — not yet a dated instance, see
 * DutyGroupInstance. docs/allocation-algorithm.md §9/§14 requires these
 * groups to become a single atomic unit for the future solver; a pattern
 * with a variable number of components (2 for a weekend, 2 for Dec24+25,
 * potentially more) is naturally a one-to-many relation
 * (DutyPatternComponent) rather than a fixed set of columns or an
 * unqueryable JSON blob — see docs/planning-domain.md for the rationale.
 *
 * $family (docs/decisions.md D136) classifies this pattern into an equity
 * bucket ("Week-end", "Semaine", ...) — nullable and immutable once set:
 * nullable because every pre-D136 pattern (and every test fixture that
 * never cared about equity families) has none, and a pattern with no
 * family simply never contributes to the ALLOCATION_FAMILY fairness
 * dimension, exactly like a Duty's DutyType only ever contributes a
 * DUTY_TYPE dimension actually encountered; immutable because a week
 * structure edit always replaces patterns wholesale (never mutates one in
 * place, see WeekStructureService) — a family is fixed at the moment a
 * pattern is created and never drifts out from under Duty rows already
 * materialized from it.
 *
 * $recurring (docs/decisions.md D136) marks a pattern as belonging to a
 * PlanningLine's *weekly recurring structure* (WeekStructureService),
 * distinct from `$active` on purpose: many patterns predating this lot (and
 * plenty of test fixtures) are one-off/manually-built groups
 * (`DutyMaterializationServiceTest`, `createTwoDutyGroup()`, ...) that are
 * `active = true` but were never meant to recur weekly. `WeeklyDutyCalendarService`
 * only ever reads `$recurring = true` patterns
 * (`DutyPatternRepository::findActiveRecurringByTeam()`) — conflating the
 * two would make it silently re-anchor and re-materialize an unrelated
 * one-off pattern onto every Monday of a period, which is exactly the bug
 * this field exists to make structurally impossible rather than merely
 * avoided by convention.
 */
#[ORM\Entity(repositoryClass: DutyPatternRepository::class)]
#[ORM\Table(name: 'duty_patterns')]
#[ORM\UniqueConstraint(name: 'uniq_duty_patterns_stable_id', columns: ['stable_id'])]
#[ORM\UniqueConstraint(name: 'uniq_duty_patterns_team_code', columns: ['team_id', 'code'])]
#[ORM\UniqueConstraint(name: 'uniq_duty_patterns_id_team_id', columns: ['id', 'team_id'])]
class DutyPattern
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

    #[ORM\Column(length: 50)]
    private string $code;

    #[ORM\Column(length: 150)]
    private string $name;

    #[ORM\ManyToOne(targetEntity: AllocationFamily::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'RESTRICT')]
    private ?AllocationFamily $family;

    #[ORM\Column]
    private bool $active = true;

    #[ORM\Column]
    private bool $recurring;

    /**
     * @var Collection<int, DutyPatternComponent>
     */
    #[ORM\OneToMany(targetEntity: DutyPatternComponent::class, mappedBy: 'pattern', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['dayOffset' => 'ASC'])]
    private Collection $components;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(PlanningTeam $team, string $code, string $name, ?AllocationFamily $family = null, bool $recurring = false)
    {
        if (null !== $family && $family->getTeam() !== $team) {
            throw new \InvalidArgumentException('A DutyPattern must use an AllocationFamily from the same PlanningTeam.');
        }

        $this->stableId = Uuid::v7();
        $this->team = $team;
        $this->code = $code;
        $this->name = $name;
        $this->family = $family;
        $this->recurring = $recurring;
        $this->components = new ArrayCollection();
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

    public function getCode(): string
    {
        return $this->code;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getFamily(): ?AllocationFamily
    {
        return $this->family;
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

    public function isRecurring(): bool
    {
        return $this->recurring;
    }

    /**
     * @throws \InvalidArgumentException if $dayOffset is already used, or
     *                                   $dutyType belongs to another PlanningTeam
     */
    public function addComponent(int $dayOffset, DutyType $dutyType): DutyPatternComponent
    {
        if ($dutyType->getTeam() !== $this->team) {
            throw new \InvalidArgumentException('A DutyPatternComponent must reference a DutyType from the same PlanningTeam as its DutyPattern.');
        }

        foreach ($this->components as $existing) {
            if ($existing->getDayOffset() === $dayOffset) {
                throw new \InvalidArgumentException(sprintf('This pattern already has a component at dayOffset %d.', $dayOffset));
            }
        }

        $component = new DutyPatternComponent($this, $dayOffset, $dutyType);
        $this->components->add($component);
        $this->touch();

        return $component;
    }

    /**
     * @return Collection<int, DutyPatternComponent>
     */
    public function getComponents(): Collection
    {
        return $this->components;
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
