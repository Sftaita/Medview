<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\DutyRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * A concrete, dated instance of a duty. $stableId never changes across
 * regenerations of the same PlanningPeriod — a regeneration reassigns who
 * covers a Duty, it never recreates the Duty itself. The future
 * tie-break's dutyStableKey depends on this identity surviving
 * (docs/allocation-algorithm.md §13).
 *
 * Two distinct time representations on purpose (docs/allocation-algorithm.md
 * §Duty / Dates et temps):
 *  - $startsAt/$endsAt: the exact instant (TIMESTAMPTZ), resolved once at
 *    creation from the intended local wall-clock time and $timezone. All
 *    conflict/rest-period arithmetic (MIN_REST, CONFLICT) must use these —
 *    they are DST-safe by construction because they are absolute instants.
 *  - $localDate: the plain calendar date this Duty "counts as" for
 *    fairness bucketing (day-of-week, holiday matching, requiredDemand).
 *    Convention: a Duty counts as the date it *starts* on, even if it
 *    crosses midnight (a Saturday-night duty ending Sunday morning is
 *    still a Saturday for fairness purposes) — documented once here so no
 *    downstream consumer has to re-derive or guess it.
 *
 * $team is a deliberate denormalization enabling a composite foreign key
 * (see migrations) that guarantees $dutyType belongs to the same
 * PlanningTeam as $planningPeriod, and that $groupInstance (when set) belongs to this
 * exact $planningPeriod — never another one.
 *
 * $pattern (docs/decisions.md D136) is the DutyPattern this Duty was
 * materialized from, when it was materialized through the week-structure
 * pipeline — nullable for backward compatibility with every Duty created
 * before D136 (and every test fixture using createStandaloneDuty() without
 * one). For a grouped Duty it is always exactly $groupInstance->getPattern()
 * (enforced below); for a solo (ungrouped) Duty produced from a one-component
 * pattern, it is the only place that pattern reference lives. This is the
 * single lookup `getAllocationFamily()` uses regardless of grouped/solo —
 * never two different code paths for the same question.
 */
#[ORM\Entity(repositoryClass: DutyRepository::class)]
#[ORM\Table(name: 'duties')]
#[ORM\UniqueConstraint(name: 'uniq_duties_stable_id', columns: ['stable_id'])]
#[ORM\Index(columns: ['planning_period_id'], name: 'idx_duties_planning_period_id')]
#[ORM\Index(columns: ['local_date'], name: 'idx_duties_local_date')]
#[ORM\Index(columns: ['group_instance_id'], name: 'idx_duties_group_instance_id')]
#[ORM\Index(columns: ['pattern_id'], name: 'idx_duties_pattern_id')]
class Duty
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

    #[ORM\ManyToOne(targetEntity: PlanningPeriod::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private PlanningPeriod $planningPeriod;

    #[ORM\ManyToOne(targetEntity: DutyType::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private DutyType $dutyType;

    #[ORM\ManyToOne(targetEntity: DutyGroupInstance::class, inversedBy: 'duties')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'RESTRICT')]
    private ?DutyGroupInstance $groupInstance = null;

    #[ORM\ManyToOne(targetEntity: DutyPattern::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'RESTRICT')]
    private ?DutyPattern $pattern;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $localDate;

    #[ORM\Column(type: 'datetimetz_immutable')]
    private \DateTimeImmutable $startsAt;

    #[ORM\Column(type: 'datetimetz_immutable')]
    private \DateTimeImmutable $endsAt;

    /**
     * IANA identifier the Duty was materialized in (e.g. "Europe/Brussels")
     * — kept separately because TIMESTAMPTZ only round-trips the absolute
     * instant, never the original zone name.
     */
    #[ORM\Column(length: 64)]
    private string $timezone;

    #[ORM\Column(length: 20, enumType: DutyDemandType::class)]
    private DutyDemandType $demandType;

    #[ORM\Column(length: 20, enumType: DutyCriticality::class)]
    private DutyCriticality $criticality;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        PlanningPeriod $planningPeriod,
        DutyType $dutyType,
        \DateTimeImmutable $startsAt,
        \DateTimeImmutable $endsAt,
        string $timezone,
        DutyDemandType $demandType = DutyDemandType::REQUIRED,
        DutyCriticality $criticality = DutyCriticality::STANDARD,
        ?DutyGroupInstance $groupInstance = null,
        ?DutyPattern $pattern = null,
    ) {
        if ($endsAt <= $startsAt) {
            throw new \InvalidArgumentException('endsAt must be strictly after startsAt.');
        }

        if ($dutyType->getTeam() !== $planningPeriod->getTeam()) {
            throw new \InvalidArgumentException('A Duty must use a DutyType from the same PlanningTeam as its PlanningPeriod.');
        }

        if (null !== $groupInstance && $groupInstance->getPlanningPeriod() !== $planningPeriod) {
            throw new \InvalidArgumentException('A Duty must belong to the same PlanningPeriod as its DutyGroupInstance.');
        }

        if (null !== $groupInstance && null !== $pattern && $pattern !== $groupInstance->getPattern()) {
            throw new \InvalidArgumentException('A grouped Duty\'s $pattern, when given explicitly, must be its DutyGroupInstance\'s own pattern — never a different one.');
        }

        if (null !== $pattern && $pattern->getTeam() !== $planningPeriod->getTeam()) {
            throw new \InvalidArgumentException('A Duty must use a DutyPattern from the same PlanningTeam as its PlanningPeriod.');
        }

        // A DutyGroupInstance is one atomic DutyUnit for eligibility AND for
        // fairness (docs/fairness.md, docs/decisions.md D083): it
        // must be classifiable as a single requiredDutyUnit/optionalDutyUnit,
        // never a mix. DutyMaterializationService::materializeGroup() only
        // ever applies one $demandType to a whole group, so this can never
        // trigger through the real application code path today — kept as
        // defense-in-depth against direct entity construction, same style as
        // the two checks above.
        if (null !== $groupInstance && !$groupInstance->getDuties()->isEmpty()) {
            $existingDemandType = $groupInstance->getDuties()->first()->getDemandType();
            if ($existingDemandType !== $demandType) {
                throw new \InvalidArgumentException('A DutyGroupInstance cannot mix REQUIRED and OPTIONAL Duties — it must be classifiable as a single requiredDutyUnit or optionalDutyUnit.');
            }
        }

        $this->stableId = Uuid::v7();
        $this->team = $planningPeriod->getTeam();
        $this->planningPeriod = $planningPeriod;
        $this->dutyType = $dutyType;
        $this->groupInstance = $groupInstance;
        // A grouped Duty's pattern is always derived from its group, never
        // trusted as a possibly-divergent explicit value (the check above
        // only rejects an inconsistent one, it never picks between them) —
        // for a solo Duty, $pattern is the only source.
        $this->pattern = $groupInstance?->getPattern() ?? $pattern;
        $this->startsAt = $startsAt;
        $this->endsAt = $endsAt;
        $this->timezone = $timezone;
        // A fresh DateTimeImmutable built from just the Y-m-d string —
        // never the timezone-converted moment itself — so $localDate is a
        // pure calendar date with no residual UTC-offset attached (it maps
        // to a plain SQL DATE column, and every other business-date field
        // in this domain is likewise timezone-naive; carrying an offset
        // here would make in-memory date comparisons subtly wrong before
        // any database round-trip normalized it away).
        $localWallClockDate = (clone $startsAt)->setTimezone(new \DateTimeZone($timezone));
        $this->localDate = new \DateTimeImmutable($localWallClockDate->format('Y-m-d'));
        $this->demandType = $demandType;
        $this->criticality = $criticality;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();

        $groupInstance?->addDuty($this);
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

    public function getPlanningPeriod(): PlanningPeriod
    {
        return $this->planningPeriod;
    }

    public function getDutyType(): DutyType
    {
        return $this->dutyType;
    }

    public function getGroupInstance(): ?DutyGroupInstance
    {
        return $this->groupInstance;
    }

    public function getPattern(): ?DutyPattern
    {
        return $this->pattern;
    }

    /**
     * The equity bucket this Duty belongs to (docs/decisions.md D136) —
     * null whenever it was never materialized from a family-classified
     * pattern (pre-D136 duties, or a pattern its team never classified).
     */
    public function getAllocationFamily(): ?AllocationFamily
    {
        return $this->pattern?->getFamily();
    }

    public function getLocalDate(): \DateTimeImmutable
    {
        return $this->localDate;
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

    public function getDemandType(): DutyDemandType
    {
        return $this->demandType;
    }

    public function isRequired(): bool
    {
        return DutyDemandType::REQUIRED === $this->demandType;
    }

    public function getCriticality(): DutyCriticality
    {
        return $this->criticality;
    }

    public function isCritical(): bool
    {
        return DutyCriticality::CRITICAL === $this->criticality;
    }

    /**
     * Exact-instant overlap check (docs/allocation-algorithm.md CONFLICT)
     * — always temporally exact regardless of DST, since it compares
     * absolute instants, never local wall-clock values.
     */
    public function overlapsWith(self $other): bool
    {
        return $this->startsAt < $other->endsAt && $other->startsAt < $this->endsAt;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
