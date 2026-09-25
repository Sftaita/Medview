<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\PlanningGeneration;
use App\Entity\PlanningGenerationStatus;
use App\Entity\PlanningPeriod;
use App\Entity\PlanningPeriodStatus;
use App\Entity\PlanningSnapshot;
use App\Entity\RestPolicyOptions;
use App\Entity\SolverParameterSet;
use App\Entity\SolverRunMetadata;
use App\Entity\User;
use App\Exception\NoSolverParameterSetException;
use App\Exception\PlanningGenerationConcurrentSolveException;
use App\Exception\StalePlanningGenerationDataException;
use App\Fairness\CoverageStatus;
use App\Fairness\OptimizationProblem;
use App\Fairness\OptimizationResult;
use App\Fairness\PlanningSolver;
use App\Fairness\SolverStatus;
use App\Repository\PlanningSnapshotRepository;
use App\Repository\PlanningSnapshotRuleSetRepository;
use App\Repository\PlanningTeamMemberRepository;
use App\Repository\SolverParameterSetRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\OptimisticLockException;

/**
 * Orchestrates a full, real generation attempt (docs/decisions.md D106):
 *
 *   PlanningGeneration (SNAPSHOTTED) → OptimizationProblem → PlanningSolver
 *     → OptimizationResult → DutyAssignment AUTO (persisted atomically)
 *
 * `create()` (Lot 3) is unchanged. `generate()` (Lot 6E) is the new
 * orchestration — controllers never perform any of these steps themselves
 * (CLAUDE.md: "aucune logique métier dans les contrôleurs").
 */
final class PlanningGenerationService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PlanningSnapshotRepository $snapshotRepository,
        private readonly PlanningSnapshotRuleSetRepository $snapshotRuleSetRepository,
        private readonly PlanningTeamMemberRepository $teamMemberRepository,
        private readonly SolverParameterSetRepository $parameterSetRepository,
        private readonly EligibilityMatrixBuilder $matrixBuilder,
        private readonly FairnessContextBuilder $contextBuilder,
        private readonly OptimizationProblemBuilder $problemBuilder,
        private readonly PlanningSolver $solver,
        private readonly SnapshotHasher $snapshotHasher,
        private readonly SeedMaterialBuilder $seedMaterialBuilder,
        private readonly DutyAssignmentService $assignmentService,
        private readonly UnsatReportPresenter $unsatReportPresenter,
    ) {
    }

    public function create(PlanningPeriod $planningPeriod, ?User $createdBy, ?RestPolicyOptions $restPolicy = null): PlanningGeneration
    {
        $generation = new PlanningGeneration($planningPeriod, $createdBy, $restPolicy ?? RestPolicyOptions::none());
        $this->entityManager->persist($generation);
        $this->entityManager->flush();

        return $generation;
    }

    /**
     * @throws PlanningGenerationConcurrentSolveException if $generation is
     *                                                    not SNAPSHOTTED,
     *                                                    or another solve
     *                                                    claimed it first
     *                                                    (real DB-level
     *                                                    optimistic-lock
     *                                                    race, never just
     *                                                    an in-memory
     *                                                    check)
     * @throws NoSolverParameterSetException              if the system has
     *                                                    never been seeded
     *                                                    with a
     *                                                    SolverParameterSet
     * @throws StalePlanningGenerationDataException       if a new Duty was
     *                                                    added to this
     *                                                    PlanningPeriod
     *                                                    while the solve
     *                                                    subprocess was
     *                                                    running — the
     *                                                    generation is left
     *                                                    FAILED, nothing is
     *                                                    ever persisted
     */
    public function generate(PlanningGeneration $generation): OptimizationResult
    {
        $this->claimSolving($generation);

        $snapshot = $this->snapshotRepository->findOneByGeneration($generation);
        if (null === $snapshot) {
            // Cannot happen through the real application flow (claimSolving()
            // already required status === SNAPSHOTTED, and a generation only
            // reaches that status once PlanningSnapshotService has created
            // exactly one PlanningSnapshot for it) — defense in depth, not a
            // path any test drives through the public API.
            throw new \LogicException('A SNAPSHOTTED PlanningGeneration has no PlanningSnapshot — data integrity bug.');
        }

        $parameterSet = $this->parameterSetRepository->findLatest();
        if (null === $parameterSet) {
            throw new NoSolverParameterSetException();
        }

        $rulesVersion = $this->snapshotRuleSetRepository->findOneBySnapshot($snapshot)?->getSourceVersion() ?? 0;
        $snapshotHashBefore = $this->snapshotHasher->hash($snapshot);
        $seed = $this->seedMaterialBuilder->build($snapshot, $rulesVersion, $snapshotHashBefore, OptimizationProblemBuilder::ALGORITHM_VERSION, $parameterSet->getVersion());

        $matrix = $this->matrixBuilder->build($snapshot);
        $context = $this->contextBuilder->build($snapshot, $matrix);
        $problem = $this->problemBuilder->build($context, $parameterSet);

        $result = $this->solver->solve($problem);

        $metadata = new SolverRunMetadata(
            OptimizationProblemBuilder::ALGORITHM_VERSION,
            $result->solverMetadata?->solverType ?? 'unknown',
            $result->solverMetadata?->solverVersion ?? '',
            $parameterSet->getVersion(),
            $seed,
            $snapshotHashBefore,
            $result->strictSolverStatus,
            $result->partialSolverStatus,
            $result->coverageStatus,
            $result->objectiveValues,
            $result->optimality,
            $result->solverMetadata?->solveDurationMs ?? 0,
            $result->solverMetadata?->timeoutHit ?? false,
            $this->describeOutcome($result),
            // Persisted exactly as computed here, never recomputed later (docs/decisions.md D130) —
            // same condition and same shape `POST .../solve` already returned transiently.
            CoverageStatus::INCOMPLETE === $result->coverageStatus
                ? $this->unsatReportPresenter->toArray($result->diagnostics)
                : null,
        );

        if ($this->isUsableOutcome($result)) {
            $this->persistSuccessfulOutcome($generation, $snapshot, $problem, $result, $metadata, $parameterSet, $snapshotHashBefore);
        } else {
            $this->persistFailedOutcome($generation, $metadata, $parameterSet);
        }

        return $result;
    }

    /**
     * The real, DB-level concurrency claim (docs/decisions.md D106) —
     * relies on PlanningGeneration::$lockVersion (Doctrine optimistic
     * locking), never merely an in-memory status check. Its own tiny
     * transaction, deliberately separate from the big one at the end of
     * generate(): claiming the slot must be visible to a concurrent
     * request immediately, before the (potentially long) solve even
     * starts.
     *
     * @throws PlanningGenerationConcurrentSolveException
     */
    private function claimSolving(PlanningGeneration $generation): void
    {
        if (PlanningGenerationStatus::SNAPSHOTTED !== $generation->getStatus()) {
            throw new PlanningGenerationConcurrentSolveException();
        }

        $generation->transitionTo(PlanningGenerationStatus::SOLVING);

        try {
            $this->entityManager->flush();
        } catch (OptimisticLockException) {
            throw new PlanningGenerationConcurrentSolveException('Another solve already claimed this PlanningGeneration concurrently.');
        }
    }

    private function isUsableOutcome(OptimizationResult $result): bool
    {
        if (SolverStatus::ERROR === $result->strictSolverStatus || SolverStatus::UNKNOWN === $result->strictSolverStatus) {
            return false;
        }

        if (\in_array($result->strictSolverStatus, [SolverStatus::OPTIMAL, SolverStatus::FEASIBLE], true)) {
            return true;
        }

        // strictSolverStatus === UNSATISFIABLE: only usable if PARTIAL
        // actually concluded with a real (possibly incomplete) result.
        return \in_array($result->partialSolverStatus, [SolverStatus::OPTIMAL, SolverStatus::FEASIBLE], true);
    }

    private function describeOutcome(OptimizationResult $result): ?string
    {
        if ($this->isUsableOutcome($result)) {
            return null;
        }

        if (isset($result->solverMetadata?->parameters['error'])) {
            return (string) $result->solverMetadata->parameters['error'];
        }

        return sprintf(
            'strictSolverStatus=%s, partialSolverStatus=%s — no usable assignment set was produced.',
            $result->strictSolverStatus->value,
            $result->partialSolverStatus?->value ?? 'null',
        );
    }

    /**
     * @throws StalePlanningGenerationDataException
     */
    private function persistSuccessfulOutcome(
        PlanningGeneration $generation,
        PlanningSnapshot $snapshot,
        OptimizationProblem $problem,
        OptimizationResult $result,
        SolverRunMetadata $metadata,
        SolverParameterSet $parameterSet,
        string $snapshotHashBefore,
    ): void {
        // docs/allocation-algorithm.md §15 "Concurrence", docs/decisions.md
        // D106: the only input that can genuinely drift after the snapshot
        // was taken is the live Duty list (docs/planning-generation.md §4 —
        // Duty is never duplicated into the snapshot). Re-hashing right
        // before persisting and comparing against the hash computed right
        // before solving catches a Duty added while the (real, non-instant)
        // subprocess was running — nothing is ever persisted against a
        // configuration that became stale mid-solve.
        $snapshotHashAfter = $this->snapshotHasher->hash($snapshot);
        if ($snapshotHashAfter !== $snapshotHashBefore) {
            $staleMetadata = new SolverRunMetadata(
                $metadata->algorithmVersion,
                $metadata->solverType,
                $metadata->solverVersion,
                $metadata->solverParameterSetVersion,
                $metadata->seed,
                $snapshotHashBefore,
                $metadata->strictSolverStatus,
                $metadata->partialSolverStatus,
                $metadata->coverageStatus,
                $metadata->objectiveValues,
                $metadata->optimality,
                $metadata->solveDurationMs,
                $metadata->timeoutHit,
                'A Duty was added to this PlanningPeriod while the solve was in progress — the computed result was discarded, nothing was persisted.',
                // The whole outcome was discarded — never expose a diagnostic for a result that never really happened.
                null,
            );
            $generation->recordSolverRun($staleMetadata, $parameterSet);
            $generation->transitionTo(PlanningGenerationStatus::FAILED);
            $this->entityManager->flush();

            throw new StalePlanningGenerationDataException();
        }

        $dutyUnitsByKey = [];
        foreach ([...$problem->getRequiredDutyUnits(), ...$problem->getOptionalDutyUnits()] as $unit) {
            $dutyUnitsByKey[$unit->getStableKey()] = $unit;
        }

        $teamMembersByStableId = [];
        foreach ($result->assignments as $edge) {
            $teamMember = $teamMembersByStableId[$edge->sourceTeamMemberStableId]
                ??= $this->teamMemberRepository->findOneByStableId($edge->sourceTeamMemberStableId);

            if (null === $teamMember) {
                // The solver only ever assigns to a candidate id that came
                // from EligibilityMatrix, which is itself built from real
                // PlanningSnapshotMember rows (docs/eligibility.md) — a
                // missing live PlanningTeamMember here would mean the
                // adapter fabricated an id, a bug, not a real runtime case.
                throw new \LogicException(sprintf('OrToolsPlanningSolver returned an assignment for an unknown TeamMember stableId "%s".', $edge->sourceTeamMemberStableId));
            }

            $unit = $dutyUnitsByKey[$edge->dutyUnitStableKey] ?? null;
            if (null === $unit) {
                throw new \LogicException(sprintf('OrToolsPlanningSolver returned an assignment for an unknown DutyUnit stableKey "%s".', $edge->dutyUnitStableKey));
            }

            // DutyGroupInstance atomicity (docs/planning-solver.md §15):
            // one CP-SAT decision on the group becomes one DutyAssignment
            // per constituent Duty, all to the same candidate — the group
            // was one node in the solve, and stays one indivisible outcome
            // here.
            foreach ($unit->getDuties() as $duty) {
                $this->assignmentService->createAuto($generation, $snapshot, $duty, $teamMember);
            }
        }

        $generation->recordSolverRun($metadata, $parameterSet);
        $generation->transitionTo(PlanningGenerationStatus::COMPLETED);

        $planningPeriod = $generation->getPlanningPeriod();
        if ($planningPeriod->getStatus()->canTransitionTo(PlanningPeriodStatus::GENERATED)) {
            $planningPeriod->transitionTo(PlanningPeriodStatus::GENERATED);
        }
        // A PUBLISHED PlanningPeriod's status is deliberately left
        // untouched (its own transition graph never allows going back to
        // GENERATED, docs/allocation-algorithm.md §18) — this generation
        // still exists as its own valid historical record either way.

        $this->entityManager->flush();
    }

    private function persistFailedOutcome(PlanningGeneration $generation, SolverRunMetadata $metadata, SolverParameterSet $parameterSet): void
    {
        $generation->recordSolverRun($metadata, $parameterSet);
        $generation->transitionTo(PlanningGenerationStatus::FAILED);
        // Never any DutyAssignment for a FAILED outcome (docs/decisions.md
        // D106) — only the generation's own audit metadata is written.
        $this->entityManager->flush();
    }
}
