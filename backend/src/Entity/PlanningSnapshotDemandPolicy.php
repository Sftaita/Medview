<?php

declare(strict_types=1);

namespace App\Entity;

use App\Demand\DemandMode;
use App\Demand\DemandRules;
use App\Demand\DemandTriggerRule;
use App\Repository\PlanningSnapshotDemandPolicyRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * The demand policy a conditional line's generation was built with, frozen
 * in its snapshot (docs/decisions.md D164): which version, which source
 * line, which generation of that source line was read, and a full copy of
 * its triggers. The snapshot can therefore explain its own demand forever,
 * whatever becomes of the live PlanningLineDemandPolicy afterwards (a new
 * version, a deleted source line...).
 *
 * Values only, never a relation to the live policy. At most one per
 * snapshot, only for a conditional line. No setter.
 */
#[ORM\Entity(repositoryClass: PlanningSnapshotDemandPolicyRepository::class)]
#[ORM\Table(name: 'planning_snapshot_demand_policies')]
#[ORM\UniqueConstraint(name: 'uniq_snapshot_demand_policies_snapshot', columns: ['snapshot_id'])]
class PlanningSnapshotDemandPolicy
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: PlanningSnapshot::class, inversedBy: 'demandPolicies')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private PlanningSnapshot $snapshot;

    #[ORM\Column(type: 'uuid')]
    private Uuid $policyStableId;

    #[ORM\Column]
    private int $policyVersion;

    #[ORM\Column(type: 'uuid')]
    private Uuid $sourceLineStableId;

    /** The source line's generation whose calendar was read to decide the demand. */
    #[ORM\Column(type: 'uuid')]
    private Uuid $sourceGenerationStableId;

    /**
     * @var Collection<int, PlanningSnapshotDemandTrigger>
     */
    #[ORM\OneToMany(targetEntity: PlanningSnapshotDemandTrigger::class, mappedBy: 'demandPolicy')]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $triggers;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(PlanningSnapshot $snapshot, Uuid $policyStableId, int $policyVersion, Uuid $sourceLineStableId, Uuid $sourceGenerationStableId)
    {
        $this->snapshot = $snapshot;
        $this->policyStableId = $policyStableId;
        $this->policyVersion = $policyVersion;
        $this->sourceLineStableId = $sourceLineStableId;
        $this->sourceGenerationStableId = $sourceGenerationStableId;
        $this->triggers = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
        $snapshot->addDemandPolicy($this);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSnapshot(): PlanningSnapshot
    {
        return $this->snapshot;
    }

    public function getPolicyStableId(): Uuid
    {
        return $this->policyStableId;
    }

    public function getPolicyVersion(): int
    {
        return $this->policyVersion;
    }

    public function getSourceLineStableId(): Uuid
    {
        return $this->sourceLineStableId;
    }

    public function getSourceGenerationStableId(): Uuid
    {
        return $this->sourceGenerationStableId;
    }

    /**
     * @return Collection<int, PlanningSnapshotDemandTrigger>
     */
    public function getTriggers(): Collection
    {
        return $this->triggers;
    }

    /**
     * @internal
     */
    public function addTrigger(PlanningSnapshotDemandTrigger $trigger): void
    {
        if (!$this->triggers->contains($trigger)) {
            $this->triggers->add($trigger);
        }
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** The frozen rules, as plain values — exactly what was evaluated, never the live policy. */
    public function toRules(): DemandRules
    {
        return new DemandRules(
            DemandMode::CONDITIONAL_ON_SOURCE_ASSIGNMENT,
            (string) $this->sourceLineStableId,
            array_values(array_map(static fn (PlanningSnapshotDemandTrigger $t): DemandTriggerRule => $t->toRule(), $this->triggers->toArray())),
        );
    }
}
