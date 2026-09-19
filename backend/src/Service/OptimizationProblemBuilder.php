<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\SolverParameterSet;
use App\Fairness\CoveragePolicy;
use App\Fairness\FairnessContext;
use App\Fairness\OptimizationMode;
use App\Fairness\OptimizationProblem;

/**
 * The final step of the fairness pipeline (docs/fairness.md
 * §OptimizationProblemBuilder):
 *
 *   FairnessContext → OptimizationProblemBuilder → OptimizationProblem(GENERATE)
 *
 * Deliberately stops here — no PlanningSolver implementation, no
 * OR-Tools/CP-SAT, no greedy algorithm, no automatic DutyAssignment, ever,
 * in this lot (CLAUDE.md). Only ever produces `mode = GENERATE` — see
 * OptimizationMode's docblock. `objectivePhases` (Lot 6A,
 * docs/planning-solver.md) is built from the context's *applicable*
 * dimensions (never merely supported — a NOT_APPLICABLE dimension has
 * target 0 for everyone and contributes nothing to track), via
 * `ObjectivePhaseFactory`. `assignmentConflicts` (Lot 6D,
 * docs/decisions.md D100) via `AssignmentConflictAnalyzer`.
 *
 * `$parameterSet` (Lot 6E, docs/decisions.md D106) is passed in already
 * resolved — this builder never looks one up itself (same "receive
 * already-resolved inputs, no hidden repository reach" discipline as the
 * rest of this class): `PlanningGenerationService` resolves
 * `SolverParameterSetRepository::findLatest()` once per solve and hands it
 * down explicitly.
 */
final class OptimizationProblemBuilder
{
    /**
     * The version of this pipeline's own business behavior
     * (docs/allocation-algorithm.md §14, docs/decisions.md D106) — never
     * the application version, never the OR-Tools version, never
     * incremented automatically. Bump this by hand only when
     * `OptimizationProblemBuilder`/its real inputs (eligibility rules,
     * fairness formulas, objective phases) change in a way that could
     * produce a different result for the same snapshot.
     */
    public const ALGORITHM_VERSION = 'allocation-v1';

    public function __construct(
        private readonly ObjectivePhaseFactory $phaseFactory,
        private readonly AssignmentConflictAnalyzer $conflictAnalyzer,
    ) {
    }

    public function build(FairnessContext $context, SolverParameterSet $parameterSet): OptimizationProblem
    {
        $matrix = $context->getEligibilityMatrix();

        $requiredDutyUnits = [];
        $optionalDutyUnits = [];
        $criticalDutyUnitStableKeys = [];
        $dimensionMembership = [];

        foreach ($matrix->getDutyUnits() as $unit) {
            if ($unit->isRequired()) {
                $requiredDutyUnits[] = $unit;
            } else {
                $optionalDutyUnits[] = $unit;
            }

            foreach ($unit->getDuties() as $duty) {
                if ($duty->isCritical()) {
                    $criticalDutyUnitStableKeys[] = $unit->getStableKey();
                    break;
                }
            }

            $dimensionMembership[$unit->getStableKey()] = $context->getDimensionMembership($unit->getStableKey());
        }

        $structurallyForcedLoad = [];
        $fairnessTargets = [];
        foreach ($context->getCandidates() as $candidate) {
            $structurallyForcedLoad[$candidate->sourceUserStableId] = $context->getStructurallyForcedLoad($candidate->sourceUserStableId);
            // discretionaryTargetAtSolve (docs/decisions.md D085) — what the
            // abstract contract's `fairnessTargets` field actually means
            // (docs/allocation-algorithm.md §21), never grossTarget itself.
            $fairnessTargets[$candidate->sourceUserStableId] = $context->getDiscretionaryTarget($candidate->sourceUserStableId);
        }

        $coveragePolicy = new CoveragePolicy(requireStrictFirst: true, criticalDutyUnitStableKeys: $criticalDutyUnitStableKeys);
        $objectivePhases = $this->phaseFactory->forMode(OptimizationMode::GENERATE, $context->getApplicableDimensions());
        $assignmentConflicts = $this->conflictAnalyzer->analyze($matrix, $context->getPlanningSnapshot());

        return new OptimizationProblem(
            OptimizationMode::GENERATE,
            $requiredDutyUnits,
            $optionalDutyUnits,
            $context->getRequiredDemand(),
            $matrix,
            $structurallyForcedLoad,
            $fairnessTargets,
            $dimensionMembership,
            $coveragePolicy,
            $objectivePhases,
            $assignmentConflicts,
            $parameterSet->getTimeoutSeconds(),
            $parameterSet->getNumWorkers(),
        );
    }
}
