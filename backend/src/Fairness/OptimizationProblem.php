<?php

declare(strict_types=1);

namespace App\Fairness;

use App\Eligibility\DutyUnit;
use App\Eligibility\EligibilityMatrix;

/**
 * The abstract, solver-independent problem contract
 * (docs/allocation-algorithm.md §21, docs/fairness.md §OptimizationProblem)
 * — the domain never depends on OR-Tools/CP-SAT directly (CLAUDE.md).
 *
 * Deliberately narrower than the full future contract in
 * docs/allocation-algorithm.md §21: `fixedAssignments`, `seedMaterial`,
 * `snapshotHash`, `solverVersion`, `timeoutBudget`, `changeCostByUnit` are
 * NOT fields here — this lot has no real, unambiguous source for any of
 * them yet (no lock/fixed-assignment concept exists for a GENERATE
 * problem, no snapshot hash is computed, REPAIR's changeCostByUnit needs a
 * change-cost model that does not exist). Adding them now would be
 * inventing data, exactly what this lot must not do.
 *
 * `objectivePhases` (added Lot 6A, docs/planning-solver.md) is the one
 * exception: §11's lexicographic phases are now fully represented
 * (`ObjectivePhase`/`ObjectivePhaseFactory`) even though no solver yet
 * consumes them.
 *
 * `assignmentConflicts` (Lot 6D, docs/decisions.md D100) is the first
 * *global* constraint — links two otherwise independently-eligible
 * `(DutyUnit, candidate)` pairs to each other
 * (`x[left,c] + x[right,c] <= 1`), something `EligibilityMatrix` alone
 * cannot express (it only ever removes one edge at a time).
 *
 * `timeoutSeconds`/`numWorkers` (Lot 6E, docs/decisions.md D106) are the
 * first two fields of the abstract spec's `timeoutBudget` genuinely
 * consumed by a real solver: `CpSatPayloadBuilder` threads them into every
 * CP-SAT payload instead of the `numWorkers = 1` literal it used to
 * hardcode at three call sites, and `cp_sat_solver.py` sets
 * `max_time_in_seconds` on every `Solve()` call. Still no `fixedAssignments`,
 * `seedMaterial`, `changeCostByUnit` here — `seedMaterial` is computed and
 * persisted for audit only (`SeedMaterialBuilder`), never consumed by a
 * solve in this lot (phase 8 stays neutral, D088), so it has no reason to
 * live on the solver-facing contract.
 *
 * This lot's OptimizationProblemBuilder only ever produces `mode =
 * GENERATE` — see OptimizationMode's docblock for why the enum still
 * carries all three values.
 */
final readonly class OptimizationProblem
{
    /**
     * @param list<DutyUnit>                         $requiredDutyUnits
     * @param list<DutyUnit>                         $optionalDutyUnits
     * @param array<string, FairnessDimensionValues> $structurallyForcedLoad keyed by sourceUserStableId
     * @param array<string, FairnessDimensionValues> $fairnessTargets        keyed by sourceUserStableId — discretionaryTargetAtSolve (docs/decisions.md D085)
     * @param array<string, FairnessDimensionValues> $dimensionMembership    keyed by DutyUnit::getStableKey()
     * @param list<ObjectivePhase>                   $objectivePhases        ordered, GENERATE only in this lot (docs/decisions.md D087)
     * @param list<AssignmentConflict>               $assignmentConflicts    docs/decisions.md D100
     * @param int|null                               $timeoutSeconds         per-Solve()-call CP-SAT budget (docs/decisions.md D106); `null` only for legacy/direct test construction that does not care — never a real production value
     * @param int                                    $numWorkers             CP-SAT `num_search_workers`; `1` is required for determinism (docs/planning-solver.md §20)
     */
    public function __construct(
        private OptimizationMode $mode,
        private array $requiredDutyUnits,
        private array $optionalDutyUnits,
        private FairnessDimensionValues $requiredDemand,
        private EligibilityMatrix $eligibilityMatrix,
        private array $structurallyForcedLoad,
        private array $fairnessTargets,
        private array $dimensionMembership,
        private CoveragePolicy $coveragePolicy,
        private array $objectivePhases,
        private array $assignmentConflicts,
        private ?int $timeoutSeconds = null,
        private int $numWorkers = 1,
    ) {
    }

    public function getMode(): OptimizationMode
    {
        return $this->mode;
    }

    /**
     * @return list<DutyUnit>
     */
    public function getRequiredDutyUnits(): array
    {
        return $this->requiredDutyUnits;
    }

    /**
     * @return list<DutyUnit>
     */
    public function getOptionalDutyUnits(): array
    {
        return $this->optionalDutyUnits;
    }

    public function getRequiredDemand(): FairnessDimensionValues
    {
        return $this->requiredDemand;
    }

    public function getEligibilityMatrix(): EligibilityMatrix
    {
        return $this->eligibilityMatrix;
    }

    public function getStructurallyForcedLoad(string $sourceUserStableId): FairnessDimensionValues
    {
        return $this->structurallyForcedLoad[$sourceUserStableId] ?? FairnessDimensionValues::empty();
    }

    public function getFairnessTarget(string $sourceUserStableId): FairnessDimensionValues
    {
        return $this->fairnessTargets[$sourceUserStableId] ?? FairnessDimensionValues::empty();
    }

    public function getDimensionMembership(string $dutyUnitStableKey): FairnessDimensionValues
    {
        return $this->dimensionMembership[$dutyUnitStableKey] ?? FairnessDimensionValues::empty();
    }

    public function getCoveragePolicy(): CoveragePolicy
    {
        return $this->coveragePolicy;
    }

    /**
     * @return list<ObjectivePhase>
     */
    public function getObjectivePhases(): array
    {
        return $this->objectivePhases;
    }

    /**
     * @return list<AssignmentConflict>
     */
    public function getAssignmentConflicts(): array
    {
        return $this->assignmentConflicts;
    }

    public function getTimeoutSeconds(): ?int
    {
        return $this->timeoutSeconds;
    }

    public function getNumWorkers(): int
    {
        return $this->numWorkers;
    }
}
