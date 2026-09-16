<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\DutyGroupInstanceRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * The dated instance of a DutyPattern within one PlanningPeriod — e.g.
 * "WEEKEND_FULL, anchored on 2027-03-12" materializes into the Friday +
 * Saturday + Sunday Duty rows of that specific weekend. Each constituent
 * Duty stays a distinct row for the analytic dimension counters (§6), but
 * the future solver treats this instance as one atomic unit (§9/§14).
 *
 * $team is a deliberate denormalization enforcing, via a composite
 * foreign key (see migrations), that $pattern belongs to the same Team as
 * $planningPeriod — a cross-table invariant a plain CHECK constraint
 * cannot express. The same composite-key technique is reused on Duty to
 * guarantee every constituent Duty belongs to this exact
 * $planningPeriod, never another one (see Duty and migrations).
 */
#[ORM\Entity(repositoryClass: DutyGroupInstanceRepository::class)]
#[ORM\Table(name: 'duty_group_instances')]
#[ORM\UniqueConstraint(name: 'uniq_duty_group_instances_stable_id', columns: ['stable_id'])]
#[ORM\UniqueConstraint(name: 'uniq_duty_group_instances_planning_period_id', columns: ['id', 'planning_period_id'])]
#[ORM\Index(columns: ['planning_period_id'], name: 'idx_duty_group_instances_planning_period_id')]
class DutyGroupInstance
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: 'uuid')]
    private Uuid $stableId;

    #[ORM\ManyToOne(targetEntity: Team::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Team $team;

    #[ORM\ManyToOne(targetEntity: PlanningPeriod::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private PlanningPeriod $planningPeriod;

    #[ORM\ManyToOne(targetEntity: DutyPattern::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private DutyPattern $pattern;

    /**
     * The calendar date dayOffset=0 of the pattern refers to.
     */
    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $anchorDate;

    /**
     * @var Collection<int, Duty>
     */
    #[ORM\OneToMany(targetEntity: Duty::class, mappedBy: 'groupInstance')]
    private Collection $duties;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(PlanningPeriod $planningPeriod, DutyPattern $pattern, \DateTimeImmutable $anchorDate)
    {
        if ($pattern->getTeam() !== $planningPeriod->getTeam()) {
            throw new \InvalidArgumentException('A DutyGroupInstance must use a DutyPattern from the same Team as its PlanningPeriod.');
        }

        $this->stableId = Uuid::v7();
        $this->team = $planningPeriod->getTeam();
        $this->planningPeriod = $planningPeriod;
        $this->pattern = $pattern;
        $this->anchorDate = $anchorDate;
        $this->duties = new ArrayCollection();
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

    public function getPlanningPeriod(): PlanningPeriod
    {
        return $this->planningPeriod;
    }

    public function getPattern(): DutyPattern
    {
        return $this->pattern;
    }

    public function getAnchorDate(): \DateTimeImmutable
    {
        return $this->anchorDate;
    }

    /**
     * @return Collection<int, Duty>
     */
    public function getDuties(): Collection
    {
        return $this->duties;
    }

    /**
     * Keeps the inverse side of the association in sync in-memory — see
     * TeamMember::addParticipationPeriod() for why this is necessary
     * (Doctrine does not populate an already-loaded inverse collection
     * just because the owning side's foreign key was set).
     *
     * @internal
     */
    public function addDuty(Duty $duty): void
    {
        if (!$this->duties->contains($duty)) {
            $this->duties->add($duty);
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
}
