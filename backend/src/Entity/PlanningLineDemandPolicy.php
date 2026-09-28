<?php

declare(strict_types=1);

namespace App\Entity;

use App\Demand\DemandMode;
use App\Demand\DemandRules;
use App\Repository\PlanningLineDemandPolicyRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * How one PlanningLine's demand is defined, versioned (docs/decisions.md
 * D162). A line with no policy at all is INDEPENDENT — the behaviour of
 * every line before D162, so no existing line needed migrating.
 *
 * Immutable once created: no setter, the triggers are fixed at
 * construction. A change is a new version (sequential per target line,
 * assigned by PlanningLineDemandPolicyService, never by the caller) created
 * ACTIVE, the previous one being RETIRED — at most one ACTIVE per line,
 * enforced by a partial unique index.
 *
 * V1 structure rules (checked by PlanningLineDemandPolicyService, the
 * database guards what it can): the source is another line of the SAME
 * Planning, active and itself INDEPENDENT (depth 1, no chain, no cycle).
 *
 * The source is kept twice: a relation ($sourceLine, set to null if that
 * line is ever deleted) and its stable id as a value ($sourceLineStableId,
 * never lost) — a retired version keeps saying which line it depended on
 * even after that line is gone.
 */
#[ORM\Entity(repositoryClass: PlanningLineDemandPolicyRepository::class)]
#[ORM\Table(name: 'planning_line_demand_policies')]
#[ORM\UniqueConstraint(name: 'uniq_demand_policies_stable_id', columns: ['stable_id'])]
#[ORM\UniqueConstraint(name: 'uniq_demand_policies_line_version', columns: ['target_line_id', 'version'])]
#[ORM\Index(columns: ['source_line_id'], name: 'idx_demand_policies_source_line')]
class PlanningLineDemandPolicy
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: 'uuid')]
    private Uuid $stableId;

    #[ORM\ManyToOne(targetEntity: PlanningLine::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private PlanningLine $targetLine;

    #[ORM\Column]
    private int $version;

    #[ORM\Column(length: 40, enumType: DemandMode::class)]
    private DemandMode $mode;

    #[ORM\ManyToOne(targetEntity: PlanningLine::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?PlanningLine $sourceLine;

    #[ORM\Column(type: 'uuid', nullable: true)]
    private ?Uuid $sourceLineStableId;

    #[ORM\Column(length: 10, enumType: DemandPolicyStatus::class)]
    private DemandPolicyStatus $status;

    /**
     * @var Collection<int, DemandTrigger>
     */
    #[ORM\OneToMany(targetEntity: DemandTrigger::class, mappedBy: 'policy')]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $triggers;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $createdBy;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $retiredAt = null;

    public function __construct(PlanningLine $targetLine, int $version, DemandMode $mode, ?PlanningLine $sourceLine, ?User $createdBy)
    {
        if ($version < 1) {
            throw new \InvalidArgumentException('A demand policy version starts at 1.');
        }
        if ($mode->isConditional() !== (null !== $sourceLine)) {
            throw new \InvalidArgumentException('A conditional demand policy has exactly one source line; an independent one has none.');
        }
        if (null !== $sourceLine && $sourceLine === $targetLine) {
            throw new \InvalidArgumentException('A line can never be its own source.');
        }
        if (null !== $sourceLine && $sourceLine->getPlanning() !== $targetLine->getPlanning()) {
            throw new \InvalidArgumentException('The source line must belong to the same Planning as the target line.');
        }

        $this->stableId = Uuid::v7();
        $this->targetLine = $targetLine;
        $this->version = $version;
        $this->mode = $mode;
        $this->sourceLine = $sourceLine;
        $this->sourceLineStableId = $sourceLine?->getStableId();
        $this->status = DemandPolicyStatus::ACTIVE;
        $this->triggers = new ArrayCollection();
        $this->createdBy = $createdBy;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getStableId(): Uuid
    {
        return $this->stableId;
    }

    public function getTargetLine(): PlanningLine
    {
        return $this->targetLine;
    }

    public function getVersion(): int
    {
        return $this->version;
    }

    public function getMode(): DemandMode
    {
        return $this->mode;
    }

    public function getSourceLine(): ?PlanningLine
    {
        return $this->sourceLine;
    }

    public function getSourceLineStableId(): ?Uuid
    {
        return $this->sourceLineStableId;
    }

    public function getStatus(): DemandPolicyStatus
    {
        return $this->status;
    }

    public function isActive(): bool
    {
        return DemandPolicyStatus::ACTIVE === $this->status;
    }

    /**
     * @return Collection<int, DemandTrigger>
     */
    public function getTriggers(): Collection
    {
        return $this->triggers;
    }

    /**
     * Keeps the inverse side in sync in-memory (same pattern as
     * PlanningSnapshot::addMember()) — only ever called by the
     * DemandTrigger constructor, before the policy is flushed.
     *
     * @internal
     */
    public function addTrigger(DemandTrigger $trigger): void
    {
        if (null !== $this->id) {
            throw new \LogicException('A demand policy is immutable once saved: a change is a new version.');
        }
        if (!$this->triggers->contains($trigger)) {
            $this->triggers->add($trigger);
        }
    }

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getRetiredAt(): ?\DateTimeImmutable
    {
        return $this->retiredAt;
    }

    /** The only change a saved version ever sees: being replaced by the next one. */
    public function retire(): void
    {
        if (DemandPolicyStatus::ACTIVE !== $this->status) {
            throw new \LogicException('Only the ACTIVE demand policy can be retired.');
        }

        $this->status = DemandPolicyStatus::RETIRED;
        $this->retiredAt = new \DateTimeImmutable();
    }

    /** As plain values, for DemandTriggerEvaluator. */
    public function toRules(): DemandRules
    {
        return new DemandRules(
            $this->mode,
            null !== $this->sourceLineStableId ? (string) $this->sourceLineStableId : null,
            array_values(array_map(static fn (DemandTrigger $t) => $t->toRule(), $this->triggers->toArray())),
        );
    }
}
