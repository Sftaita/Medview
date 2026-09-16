<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PlanningSnapshotRuleSetRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * A frozen copy of the Team's ACTIVE PlanningRuleSet at snapshot time
 * (docs/planning-generation.md §PlanningRuleSet). Carries a full copy of
 * $configuration, not just a reference: even though PlanningRuleSet is
 * already immutable once ACTIVE (D054), a *later* ruleset could be
 * activated and this snapshot must stay legible without depending on the
 * live PlanningRuleSet row still existing or still being the active one.
 *
 * At most one per PlanningSnapshot — enforced by the unique constraint on
 * $snapshot.
 */
#[ORM\Entity(repositoryClass: PlanningSnapshotRuleSetRepository::class)]
#[ORM\Table(name: 'planning_snapshot_rule_sets')]
#[ORM\UniqueConstraint(name: 'uniq_snapshot_rule_sets_snapshot_id', columns: ['snapshot_id'])]
class PlanningSnapshotRuleSet
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: PlanningSnapshot::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private PlanningSnapshot $snapshot;

    #[ORM\Column(type: 'uuid')]
    private Uuid $sourcePlanningRuleSetStableId;

    #[ORM\Column]
    private int $sourceVersion;

    /**
     * @var array<string, mixed>
     */
    #[ORM\Column(type: 'json')]
    private array $configuration;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /**
     * @param array<string, mixed> $configuration
     */
    public function __construct(
        PlanningSnapshot $snapshot,
        Uuid $sourcePlanningRuleSetStableId,
        int $sourceVersion,
        array $configuration,
    ) {
        $this->snapshot = $snapshot;
        $this->sourcePlanningRuleSetStableId = $sourcePlanningRuleSetStableId;
        $this->sourceVersion = $sourceVersion;
        $this->configuration = $configuration;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSnapshot(): PlanningSnapshot
    {
        return $this->snapshot;
    }

    public function getSourcePlanningRuleSetStableId(): Uuid
    {
        return $this->sourcePlanningRuleSetStableId;
    }

    public function getSourceVersion(): int
    {
        return $this->sourceVersion;
    }

    /**
     * @return array<string, mixed>
     */
    public function getConfiguration(): array
    {
        return $this->configuration;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
