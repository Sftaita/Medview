<?php

declare(strict_types=1);

namespace App\Entity;

use App\Exception\InvalidPlanningGenerationTransitionException;
use App\Fairness\CoverageStatus;
use App\Fairness\OptimizationMode;
use App\Fairness\SolverStatus;
use App\Repository\PlanningGenerationRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * One attempt to generate a PlanningPeriod's duty roster
 * (docs/planning-generation.md). A PlanningPeriod may accumulate several
 * successive generations over time — creating a new one never overwrites
 * or deletes an earlier one (CLAUDE.md: history is never recalculated from
 * current state).
 *
 * $stableId is what DutyAssignment and any future audit trail reference —
 * never the auto-increment $id (D046).
 *
 * $restPolicy (docs/decisions.md D105) is fixed once, at construction,
 * exactly like every other field here — a `PlanningGeneration` row is
 * never mutated after creation except its `$status`, so this is already
 * the permanent historical record `AssignmentConflictAnalyzer` reads for
 * this generation: no separate `PlanningSnapshot*` frozen-copy entity is
 * needed the way `PlanningSnapshotRuleSet` exists for the team's RuleSet
 * (that one exists specifically because the *live* RuleSet can be
 * reassigned to a different active version later — nothing here can ever
 * drift the same way).
 *
 * $mode is always `OptimizationMode::GENERATE` (docs/decisions.md D106) —
 * not a constructor parameter, because REPAIR/SIMULATE have no real
 * implementation to select yet (`ObjectivePhaseFactory` itself throws for
 * them); accepting a caller-supplied mode now would be a fake extensibility
 * surface nothing can actually use. Persisted anyway (not merely implied)
 * so the API/audit trail always states the mode explicitly.
 *
 * $lockVersion (`#[ORM\Version]`) is Doctrine's real, DB-level optimistic
 * lock — the mechanism `PlanningGenerationService::generate()` relies on to
 * make the SNAPSHOTTED → SOLVING claim atomic across concurrent requests
 * (docs/decisions.md D106): two callers racing to solve the same generation
 * both read the same `$lockVersion`, but only the first `flush()` succeeds
 * — the second's `UPDATE ... WHERE lock_version = ?` matches zero rows and
 * Doctrine throws `OptimisticLockException`, translated into a clean 409.
 * An in-memory `if status === SNAPSHOTTED` check alone cannot provide this
 * guarantee (both requests could pass it before either commits).
 *
 * The solver-run fields (`$algorithmVersion` through `$failureReason`) stay
 * `null` until `recordSolverRun()` is called exactly once, after a real
 * `PlanningSolver::solve()` — see `SolverRunMetadata`/`getSolverRunMetadata()`.
 */
#[ORM\Entity(repositoryClass: PlanningGenerationRepository::class)]
#[ORM\Table(name: 'planning_generations')]
#[ORM\UniqueConstraint(name: 'uniq_planning_generations_stable_id', columns: ['stable_id'])]
#[ORM\Index(columns: ['planning_period_id'], name: 'idx_planning_generations_planning_period_id')]
#[ORM\Index(columns: ['solver_parameter_set_id'], name: 'idx_planning_generations_solver_parameter_set_id')]
class PlanningGeneration
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: 'uuid')]
    private Uuid $stableId;

    #[ORM\ManyToOne(targetEntity: PlanningPeriod::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private PlanningPeriod $planningPeriod;

    #[ORM\Column(length: 20, enumType: PlanningGenerationStatus::class)]
    private PlanningGenerationStatus $status;

    #[ORM\Version]
    #[ORM\Column(type: 'integer')]
    private int $lockVersion = 1;

    #[ORM\Column(length: 20, enumType: OptimizationMode::class)]
    private OptimizationMode $mode;

    #[ORM\Column]
    private bool $legalMinRestEnabled;

    #[ORM\Column(nullable: true)]
    private ?int $legalMinRestHours;

    #[ORM\Column]
    private bool $teamMinRestEnabled;

    #[ORM\Column(nullable: true)]
    private ?int $teamMinRestHours;

    #[ORM\Column(nullable: true)]
    private ?string $algorithmVersion = null;

    #[ORM\Column(nullable: true)]
    private ?string $solverType = null;

    #[ORM\Column(nullable: true)]
    private ?string $solverVersion = null;

    #[ORM\ManyToOne(targetEntity: SolverParameterSet::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'RESTRICT')]
    private ?SolverParameterSet $solverParameterSet = null;

    #[ORM\Column(nullable: true)]
    private ?string $seed = null;

    #[ORM\Column(nullable: true)]
    private ?string $snapshotHash = null;

    #[ORM\Column(length: 20, nullable: true, enumType: SolverStatus::class)]
    private ?SolverStatus $strictSolverStatus = null;

    #[ORM\Column(length: 20, nullable: true, enumType: SolverStatus::class)]
    private ?SolverStatus $partialSolverStatus = null;

    #[ORM\Column(length: 20, nullable: true, enumType: CoverageStatus::class)]
    private ?CoverageStatus $coverageStatus = null;

    /**
     * @var array<string, float>|null
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $objectiveValues = null;

    /**
     * @var array<string, bool>|null
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $optimality = null;

    #[ORM\Column(nullable: true)]
    private ?int $solveDurationMs = null;

    #[ORM\Column(nullable: true)]
    private ?bool $timeoutHit = null;

    #[ORM\Column(nullable: true)]
    private ?string $failureReason = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $generatedAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'RESTRICT')]
    private ?User $createdBy = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        PlanningPeriod $planningPeriod,
        ?User $createdBy = null,
        RestPolicyOptions $restPolicy = new RestPolicyOptions(false, null, false, null),
    ) {
        $this->stableId = Uuid::v7();
        $this->planningPeriod = $planningPeriod;
        $this->status = PlanningGenerationStatus::DRAFT;
        $this->mode = OptimizationMode::GENERATE;
        $this->legalMinRestEnabled = $restPolicy->legalMinRestEnabled;
        $this->legalMinRestHours = $restPolicy->legalMinRestHours;
        $this->teamMinRestEnabled = $restPolicy->teamMinRestEnabled;
        $this->teamMinRestHours = $restPolicy->teamMinRestHours;
        $this->createdBy = $createdBy;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getRestPolicy(): RestPolicyOptions
    {
        return new RestPolicyOptions($this->legalMinRestEnabled, $this->legalMinRestHours, $this->teamMinRestEnabled, $this->teamMinRestHours);
    }

    public function getMode(): OptimizationMode
    {
        return $this->mode;
    }

    /**
     * Writes every solver-run field at once — never partially (an entity
     * caught with some solve fields set and others still null would be
     * unauditable). Does not itself transition `$status`; the caller
     * (`PlanningGenerationService::generate()`) decides COMPLETED vs
     * FAILED and calls `transitionTo()` separately — that decision is
     * business logic (whether the outcome is usable), not something this
     * entity should infer on its own from the statuses it is handed.
     *
     * `$generatedAt` marks when the solve attempt *concluded*, successful
     * or not — a FAILED generation still has a real, meaningful "when did
     * this happen" for audit purposes, so it is set unconditionally rather
     * than only on success.
     */
    public function recordSolverRun(SolverRunMetadata $metadata, SolverParameterSet $solverParameterSet): void
    {
        $this->algorithmVersion = $metadata->algorithmVersion;
        $this->solverType = $metadata->solverType;
        $this->solverVersion = $metadata->solverVersion;
        $this->solverParameterSet = $solverParameterSet;
        $this->seed = $metadata->seed;
        $this->snapshotHash = $metadata->snapshotHash;
        $this->strictSolverStatus = $metadata->strictSolverStatus;
        $this->partialSolverStatus = $metadata->partialSolverStatus;
        $this->coverageStatus = $metadata->coverageStatus;
        $this->objectiveValues = $metadata->objectiveValues;
        $this->optimality = $metadata->optimality;
        $this->solveDurationMs = $metadata->solveDurationMs;
        $this->timeoutHit = $metadata->timeoutHit;
        $this->failureReason = $metadata->failureReason;
        $this->generatedAt = new \DateTimeImmutable();
        $this->touch();
    }

    public function getAlgorithmVersion(): ?string
    {
        return $this->algorithmVersion;
    }

    public function getSolverType(): ?string
    {
        return $this->solverType;
    }

    public function getSolverVersion(): ?string
    {
        return $this->solverVersion;
    }

    public function getSolverParameterSet(): ?SolverParameterSet
    {
        return $this->solverParameterSet;
    }

    public function getSeed(): ?string
    {
        return $this->seed;
    }

    public function getSnapshotHash(): ?string
    {
        return $this->snapshotHash;
    }

    public function getStrictSolverStatus(): ?SolverStatus
    {
        return $this->strictSolverStatus;
    }

    public function getPartialSolverStatus(): ?SolverStatus
    {
        return $this->partialSolverStatus;
    }

    public function getCoverageStatus(): ?CoverageStatus
    {
        return $this->coverageStatus;
    }

    /**
     * @return array<string, float>|null
     */
    public function getObjectiveValues(): ?array
    {
        return $this->objectiveValues;
    }

    /**
     * @return array<string, bool>|null
     */
    public function getOptimality(): ?array
    {
        return $this->optimality;
    }

    public function getSolveDurationMs(): ?int
    {
        return $this->solveDurationMs;
    }

    public function isTimeoutHit(): ?bool
    {
        return $this->timeoutHit;
    }

    public function getFailureReason(): ?string
    {
        return $this->failureReason;
    }

    public function getGeneratedAt(): ?\DateTimeImmutable
    {
        return $this->generatedAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getStableId(): Uuid
    {
        return $this->stableId;
    }

    public function getPlanningPeriod(): PlanningPeriod
    {
        return $this->planningPeriod;
    }

    public function getStatus(): PlanningGenerationStatus
    {
        return $this->status;
    }

    /**
     * The only way this status ever changes — see
     * PlanningGenerationStatus::canTransitionTo() for the allowed graph.
     *
     * @throws InvalidPlanningGenerationTransitionException
     */
    public function transitionTo(PlanningGenerationStatus $target): void
    {
        if (!$this->status->canTransitionTo($target)) {
            throw new InvalidPlanningGenerationTransitionException($this->status, $target);
        }

        $this->status = $target;
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

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
