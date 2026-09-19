<?php

declare(strict_types=1);

namespace App\Service;

use App\Eligibility\DutyUnit;
use App\Fairness\AssignmentConflict;
use App\Fairness\CandidateExclusionDiagnostic;
use App\Fairness\DiagnosticRelaxation;
use App\Fairness\ExistingDataConflict;
use App\Fairness\OptimizationProblem;
use App\Fairness\SolverAnalysis;
use App\Fairness\SolverStatus;
use App\Fairness\StructuralDiagnostic;
use App\Fairness\StructuralDiagnosticCode;
use App\Fairness\UnassignedDutyDiagnostic;
use App\Fairness\UnsatReport;

/**
 * Builds the real UNSAT diagnostic (docs/allocation-algorithm.md §16,
 * docs/planning-solver.md) from data already produced by the domain —
 * `EligibilityMatrix`, `CoveragePolicy` — plus the plain list of
 * unassigned DutyUnit stable keys a PARTIAL solve reported. Solver
 * agnostic: takes no CP-SAT/OR-Tools type, reusable by any future
 * `PlanningSolver` implementation.
 */
final class UnsatDiagnosticsBuilder
{
    /**
     * @param list<string>               $unassignedDutyUnitKeys
     * @param list<DiagnosticRelaxation> $relaxations            docs/decisions.md D103 — built by the caller
     *                                                           (a real re-solve is required to confirm one),
     *                                                           never computed inside this solver-agnostic builder
     */
    public function buildForCoverageShortfall(
        OptimizationProblem $problem,
        SolverStatus $strictStatus,
        SolverStatus $partialStatus,
        array $unassignedDutyUnitKeys,
        array $relaxations = [],
    ): UnsatReport {
        $requiredUnitsByKey = [];
        foreach ($problem->getRequiredDutyUnits() as $unit) {
            $requiredUnitsByKey[$unit->getStableKey()] = $unit;
        }

        $unassignedDuties = [];
        $structuralDiagnostics = [];

        foreach ($unassignedDutyUnitKeys as $key) {
            $unit = $requiredUnitsByKey[$key] ?? null;
            if (null === $unit) {
                // A key the solver reported that does not match any
                // REQUIRED unit this problem actually has would be an
                // adapter bug, not a business fact — skip rather than
                // fabricate a diagnostic for a unit that does not exist
                // here.
                continue;
            }

            [$candidateExclusions, $eligibleIds] = $this->buildCandidateExclusions($problem, $unit);

            if ([] === $eligibleIds) {
                $structuralDiagnostics[] = new StructuralDiagnostic(StructuralDiagnosticCode::NO_ELIGIBLE_CANDIDATE, $key);
            } elseif ($this->hasProvenInsufficientCapacity($problem, $key, $eligibleIds)) {
                $structuralDiagnostics[] = new StructuralDiagnostic(StructuralDiagnosticCode::INSUFFICIENT_ELIGIBLE_CAPACITY, $key);
            }

            $unassignedDuties[] = new UnassignedDutyDiagnostic(
                $key,
                \in_array($key, $problem->getCoveragePolicy()->criticalDutyUnitStableKeys, true),
                $candidateExclusions,
            );
        }

        $requiredDutyCount = \count($problem->getRequiredDutyUnits());

        return new UnsatReport(
            $strictStatus,
            $partialStatus,
            $requiredDutyCount,
            $requiredDutyCount - \count($unassignedDutyUnitKeys),
            $unassignedDuties,
            $structuralDiagnostics,
            SolverAnalysis::unavailable(),
            $relaxations,
            null,
        );
    }

    /**
     * docs/decisions.md D098: this path is contract-ready but genuinely
     * unreachable through real CP-SAT solving today — no `fixedAssignments`
     * exist yet (D090), and without them the PARTIAL model (every REQUIRED
     * unit backed by a real `unassigned[d]` slack) is always trivially
     * satisfiable. Exercised only by a direct unit test of this method and
     * by an isolated fixture-driven test of the orchestration branch in
     * `OrToolsPlanningSolverTest` — never by an end-to-end CP-SAT scenario.
     */
    public function buildForExistingDataConflict(
        OptimizationProblem $problem,
        SolverStatus $strictStatus,
        ExistingDataConflict $conflict,
    ): UnsatReport {
        $requiredDutyCount = \count($problem->getRequiredDutyUnits());

        return new UnsatReport(
            $strictStatus,
            SolverStatus::UNSATISFIABLE,
            $requiredDutyCount,
            0,
            [],
            [],
            SolverAnalysis::unavailable(),
            [],
            $conflict,
        );
    }

    /**
     * @return array{0: list<CandidateExclusionDiagnostic>, 1: list<string>} [exclusions, eligible candidate ids]
     */
    private function buildCandidateExclusions(OptimizationProblem $problem, DutyUnit $unit): array
    {
        $exclusions = [];
        $eligibleIds = [];

        foreach ($problem->getEligibilityMatrix()->getForDutyUnit($unit) as $candidateId => $result) {
            if ($result->eligible) {
                $eligibleIds[] = (string) $candidateId;
                // An eligible-but-unselected candidate is never given a
                // fabricated exclusion reason (docs/decisions.md D095) —
                // simply absent from candidateExclusions.
                continue;
            }

            $exclusions[] = new CandidateExclusionDiagnostic((string) $candidateId, $result->exclusions);
        }

        return [$exclusions, $eligibleIds];
    }

    /**
     * docs/decisions.md D102 — the one mathematically exact case: an
     * unassigned unit $key (eligible candidates $eligibleIds) is in
     * `AssignmentConflict` with another REQUIRED unit for a candidate C,
     * where C is the *only* eligible candidate for *both* units. At most
     * one of the two can then ever be covered — proven directly, not
     * approximated. Applies regardless of whether the other unit ended up
     * assigned or itself unassigned: either way, $key's own shortfall is
     * fully explained by this pairing.
     *
     * @param list<string> $eligibleIds
     */
    private function hasProvenInsufficientCapacity(OptimizationProblem $problem, string $key, array $eligibleIds): bool
    {
        if (1 !== \count($eligibleIds)) {
            return false;
        }

        $onlyCandidate = $eligibleIds[0];
        $requiredKeys = array_map(static fn (DutyUnit $u) => $u->getStableKey(), $problem->getRequiredDutyUnits());
        $requiredKeySet = array_flip($requiredKeys);

        foreach ($problem->getAssignmentConflicts() as $conflict) {
            if ($conflict->candidateStableKey !== $onlyCandidate) {
                continue;
            }

            $other = match ($key) {
                $conflict->leftDutyUnitStableKey => $conflict->rightDutyUnitStableKey,
                $conflict->rightDutyUnitStableKey => $conflict->leftDutyUnitStableKey,
                default => null,
            };

            if (null === $other || !isset($requiredKeySet[$other])) {
                continue;
            }

            $otherUnit = $problem->getRequiredDutyUnits()[$requiredKeySet[$other]];
            $otherEligible = array_keys(array_filter(
                $problem->getEligibilityMatrix()->getForDutyUnit($otherUnit),
                static fn ($result) => $result->eligible,
            ));

            if ([$onlyCandidate] === array_values($otherEligible)) {
                return true;
            }
        }

        return false;
    }
}
