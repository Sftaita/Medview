<?php

declare(strict_types=1);

namespace App\Entity;

use App\Demand\DemandTriggerRule;
use App\Demand\Weekday;
use App\Repository\PlanningSnapshotDemandTriggerRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * A copy of one DemandTrigger as it was when a generation froze its demand
 * policy (docs/decisions.md D164): the person, the weekdays (same ISO
 * bitmask as DemandTrigger), the increment, and the live trigger's stable
 * id for audit. Values only; no setter.
 */
#[ORM\Entity(repositoryClass: PlanningSnapshotDemandTriggerRepository::class)]
#[ORM\Table(name: 'planning_snapshot_demand_triggers')]
class PlanningSnapshotDemandTrigger
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: PlanningSnapshotDemandPolicy::class, inversedBy: 'triggers')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private PlanningSnapshotDemandPolicy $demandPolicy;

    #[ORM\Column(type: 'uuid')]
    private Uuid $triggerStableId;

    #[ORM\Column(type: 'uuid')]
    private Uuid $userStableId;

    #[ORM\Column(type: 'smallint')]
    private int $weekdayMask;

    #[ORM\Column(type: 'smallint')]
    private int $increment;

    public function __construct(PlanningSnapshotDemandPolicy $demandPolicy, DemandTrigger $trigger)
    {
        $this->demandPolicy = $demandPolicy;
        $this->triggerStableId = $trigger->getStableId();
        $this->userStableId = $trigger->getUser()->getStableId();
        $mask = 0;
        foreach ($trigger->getWeekdays() as $weekday) {
            $mask |= 1 << ($weekday->isoNumber() - 1);
        }
        $this->weekdayMask = $mask;
        $this->increment = $trigger->getIncrement();
        $demandPolicy->addTrigger($this);
    }

    public function getTriggerStableId(): Uuid
    {
        return $this->triggerStableId;
    }

    public function getUserStableId(): Uuid
    {
        return $this->userStableId;
    }

    /**
     * @return list<Weekday>
     */
    public function getWeekdays(): array
    {
        return array_values(array_filter(Weekday::cases(), fn (Weekday $w): bool => 0 !== ($this->weekdayMask & (1 << ($w->isoNumber() - 1)))));
    }

    public function getIncrement(): int
    {
        return $this->increment;
    }

    public function toRule(): DemandTriggerRule
    {
        return new DemandTriggerRule((string) $this->userStableId, $this->getWeekdays(), $this->increment, (string) $this->triggerStableId);
    }
}
