<?php

declare(strict_types=1);

namespace App\Entity;

use App\Exception\ImmutableRuleSetException;
use App\Repository\PlanningRuleSetRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * One versioned snapshot of a Team's planning rules (docs/decisions.md
 * D039, docs/allocation-algorithm.md §14). $version is a per-team,
 * human-friendly sequence number ("this team's 3rd ruleset") — not
 * globally unique, so it is never what a PlanningGeneration should record
 * as `rulesVersion`. $stableId is: globally unique, immutable, and the
 * value docs/allocation-algorithm.md's `rulesVersion` actually refers to.
 *
 * Immutability is enforced by the entity itself, not just documented:
 * $configuration can only change while $status is DRAFT.
 * activate()/retire() are one-way; there is no path back to DRAFT. At
 * most one ACTIVE PlanningRuleSet may exist per Team at a time — enforced
 * by a partial unique index (see migrations), the same technique used for
 * TeamMember's single-open-membership invariant.
 */
#[ORM\Entity(repositoryClass: PlanningRuleSetRepository::class)]
#[ORM\Table(name: 'planning_rule_sets')]
#[ORM\UniqueConstraint(name: 'uniq_rule_sets_stable_id', columns: ['stable_id'])]
#[ORM\UniqueConstraint(name: 'uniq_rule_sets_team_version', columns: ['team_id', 'version'])]
class PlanningRuleSet
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

    #[ORM\Column]
    private int $version;

    #[ORM\Column(length: 20, enumType: PlanningRuleSetStatus::class)]
    private PlanningRuleSetStatus $status;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $effectiveFrom;

    /**
     * @var array<string, mixed>
     */
    #[ORM\Column(type: 'json')]
    private array $configuration;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'RESTRICT')]
    private ?User $createdBy = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    /**
     * @param array<string, mixed> $configuration
     */
    public function __construct(
        Team $team,
        int $version,
        \DateTimeImmutable $effectiveFrom,
        array $configuration,
        ?User $createdBy = null,
    ) {
        $this->stableId = Uuid::v7();
        $this->team = $team;
        $this->version = $version;
        $this->status = PlanningRuleSetStatus::DRAFT;
        $this->effectiveFrom = $effectiveFrom;
        $this->configuration = $configuration;
        $this->createdBy = $createdBy;
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

    public function getVersion(): int
    {
        return $this->version;
    }

    public function getStatus(): PlanningRuleSetStatus
    {
        return $this->status;
    }

    public function getEffectiveFrom(): \DateTimeImmutable
    {
        return $this->effectiveFrom;
    }

    /**
     * @return array<string, mixed>
     */
    public function getConfiguration(): array
    {
        return $this->configuration;
    }

    /**
     * @param array<string, mixed> $configuration
     *
     * @throws ImmutableRuleSetException if no longer DRAFT
     */
    public function updateConfiguration(array $configuration): void
    {
        $this->guardMutable();
        $this->configuration = $configuration;
        $this->touch();
    }

    /**
     * @throws ImmutableRuleSetException if no longer DRAFT
     */
    public function activate(): void
    {
        $this->guardMutable();
        $this->status = PlanningRuleSetStatus::ACTIVE;
        $this->touch();
    }

    public function retire(): void
    {
        if (PlanningRuleSetStatus::ACTIVE !== $this->status) {
            throw new \LogicException('Only an ACTIVE PlanningRuleSet can be retired.');
        }

        $this->status = PlanningRuleSetStatus::RETIRED;
        $this->touch();
    }

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    private function guardMutable(): void
    {
        if (PlanningRuleSetStatus::DRAFT !== $this->status) {
            throw new ImmutableRuleSetException();
        }
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
