<?php

declare(strict_types=1);

namespace App\Entity;

use App\Demand\DemandReason;
use App\Demand\Weekday;
use App\Repository\PlanningSnapshotDemandDecisionRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Why one conditional duty was — or was not — part of a generation's
 * problem (docs/decisions.md D164), frozen in the snapshot so the
 * generation explains itself forever, without ever re-reading the live
 * calendar or policy:
 *
 * - $duty: the conditional duty (a relation, like DutyAssignment: a Duty
 *   is immutable and never deleted);
 * - $sourceDutyStableId: its coverage source; the source line and the
 *   source generation read are on the snapshot's frozen demand policy;
 * - $weekday, $sourceUserStableId (null: nobody held it), $triggerStableId
 *   (the frozen trigger that matched, or the holder's trigger that did not
 *   cover the day, or null) and $dayReason: the evaluation of this duty's
 *   OWN day;
 * - $required: the decision for its whole unit — true, false, or NULL when
 *   it could not be determined (nobody held a source duty and no day of the
 *   unit was triggered): never silently "not required";
 * - $reason: the final reason (TRIGGERED, TRIGGERED_BY_BLOCK when another
 *   day of its block triggered it, or its own day's reason).
 *
 * One row per conditional duty per snapshot. No setter.
 */
#[ORM\Entity(repositoryClass: PlanningSnapshotDemandDecisionRepository::class)]
#[ORM\Table(name: 'planning_snapshot_demand_decisions')]
#[ORM\UniqueConstraint(name: 'uniq_snapshot_demand_decisions_duty', columns: ['snapshot_id', 'duty_id'])]
class PlanningSnapshotDemandDecision
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: PlanningSnapshot::class, inversedBy: 'demandDecisions')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private PlanningSnapshot $snapshot;

    #[ORM\ManyToOne(targetEntity: Duty::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Duty $duty;

    #[ORM\Column(type: 'uuid')]
    private Uuid $sourceDutyStableId;

    #[ORM\Column(length: 10, enumType: Weekday::class)]
    private Weekday $weekday;

    #[ORM\Column(type: 'uuid', nullable: true)]
    private ?Uuid $sourceUserStableId;

    #[ORM\Column(type: 'uuid', nullable: true)]
    private ?Uuid $triggerStableId;

    #[ORM\Column(length: 40, enumType: DemandReason::class)]
    private DemandReason $dayReason;

    #[ORM\Column(nullable: true)]
    private ?bool $required;

    #[ORM\Column(length: 40, enumType: DemandReason::class)]
    private DemandReason $reason;

    public function __construct(
        PlanningSnapshot $snapshot,
        Duty $duty,
        Weekday $weekday,
        ?Uuid $sourceUserStableId,
        ?Uuid $triggerStableId,
        DemandReason $dayReason,
        ?bool $required,
        DemandReason $reason,
    ) {
        $source = $duty->getCoverageSource() ?? throw new \InvalidArgumentException('Only a conditional duty has a demand decision.');
        if (true === $required && !\in_array($reason, [DemandReason::TRIGGERED, DemandReason::TRIGGERED_BY_BLOCK], true)) {
            throw new \InvalidArgumentException('A required conditional duty is triggered, by its own day or by its block.');
        }

        $this->snapshot = $snapshot;
        $this->duty = $duty;
        $this->sourceDutyStableId = $source->getStableId();
        $this->weekday = $weekday;
        $this->sourceUserStableId = $sourceUserStableId;
        $this->triggerStableId = $triggerStableId;
        $this->dayReason = $dayReason;
        $this->required = $required;
        $this->reason = $reason;
        $snapshot->addDemandDecision($this);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDuty(): Duty
    {
        return $this->duty;
    }

    public function getSourceDutyStableId(): Uuid
    {
        return $this->sourceDutyStableId;
    }

    public function getWeekday(): Weekday
    {
        return $this->weekday;
    }

    public function getSourceUserStableId(): ?Uuid
    {
        return $this->sourceUserStableId;
    }

    public function getTriggerStableId(): ?Uuid
    {
        return $this->triggerStableId;
    }

    public function getDayReason(): DemandReason
    {
        return $this->dayReason;
    }

    /** true / false / null = could not be determined. */
    public function getRequired(): ?bool
    {
        return $this->required;
    }

    public function getReason(): DemandReason
    {
        return $this->reason;
    }
}
