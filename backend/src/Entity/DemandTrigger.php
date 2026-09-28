<?php

declare(strict_types=1);

namespace App\Entity;

use App\Demand\DemandTriggerRule;
use App\Demand\Weekday;
use App\Repository\DemandTriggerRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * "When this person holds the source line on one of these weekdays, the
 * target line needs coverage" — one row of a PlanningLineDemandPolicy
 * (docs/decisions.md D162).
 *
 * The trigger is about a PERSON ($user), never a PlanningTeamMember: a
 * surgeon who leaves and rejoins the source line, or who also belongs to
 * other lines (D160), stays the same trigger. At most one trigger per
 * person per policy (unique index).
 *
 * $weekdays is stored as an ISO bitmask (bit 0 = Monday .. bit 6 = Sunday,
 * CHECK 1..127): compact, never an unordered free-form list, and exposed
 * only as list<Weekday>. $increment is explicit — never a "doubled"
 * boolean — so a future "+N" coverage level is a data change; V1 accepts
 * only 1 (PlanningLineDemandPolicyService, and a CHECK >= 1 in the
 * database).
 *
 * Immutable: part of its policy's version, no setter.
 */
#[ORM\Entity(repositoryClass: DemandTriggerRepository::class)]
#[ORM\Table(name: 'planning_line_demand_triggers')]
#[ORM\UniqueConstraint(name: 'uniq_demand_triggers_stable_id', columns: ['stable_id'])]
#[ORM\UniqueConstraint(name: 'uniq_demand_triggers_policy_user', columns: ['policy_id', 'user_id'])]
#[ORM\Index(columns: ['user_id'], name: 'idx_demand_triggers_user')]
class DemandTrigger
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: 'uuid')]
    private Uuid $stableId;

    #[ORM\ManyToOne(targetEntity: PlanningLineDemandPolicy::class, inversedBy: 'triggers')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private PlanningLineDemandPolicy $policy;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private User $user;

    #[ORM\Column(type: 'smallint')]
    private int $weekdayMask;

    #[ORM\Column(type: 'smallint')]
    private int $increment;

    /**
     * @param list<Weekday> $weekdays
     */
    public function __construct(PlanningLineDemandPolicy $policy, User $user, array $weekdays, int $increment)
    {
        if (!$policy->getMode()->isConditional()) {
            throw new \InvalidArgumentException('Only a conditional demand policy has triggers.');
        }
        if ([] === $weekdays) {
            throw new \InvalidArgumentException('A demand trigger needs at least one weekday.');
        }
        if ($increment < 1) {
            throw new \InvalidArgumentException('A demand trigger increment must be at least 1.');
        }

        $mask = 0;
        foreach ($weekdays as $weekday) {
            $mask |= 1 << ($weekday->isoNumber() - 1);
        }

        $this->stableId = Uuid::v7();
        $this->policy = $policy;
        $this->user = $user;
        $this->weekdayMask = $mask;
        $this->increment = $increment;
        $policy->addTrigger($this);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getStableId(): Uuid
    {
        return $this->stableId;
    }

    public function getPolicy(): PlanningLineDemandPolicy
    {
        return $this->policy;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    /**
     * @return list<Weekday> ISO order
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
        return new DemandTriggerRule((string) $this->user->getStableId(), $this->getWeekdays(), $this->increment, (string) $this->stableId);
    }
}
