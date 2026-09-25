<?php

declare(strict_types=1);

namespace App\Solver;

use App\Eligibility\ConstraintTier;
use App\Fairness\CoverageStatus;
use App\Fairness\DiagnosticRelaxation;
use App\Fairness\DutyAssignmentEdge;
use App\Fairness\ExistingDataConflict;
use App\Fairness\ExistingDataConflictType;
use App\Fairness\ObjectivePhaseId;
use App\Fairness\OptimizationProblem;
use App\Fairness\OptimizationResult;
use App\Fairness\PlanningSolver;
use App\Fairness\SolverMetadata;
use App\Fairness\SolverStatus;
use App\Fairness\UnassignedDutyUnit;
use App\Fairness\UnsatDiagnostics;
use App\Service\UnsatDiagnosticsBuilder;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * The first real `PlanningSolver` (docs/decisions.md D031/D090,
 * docs/planning-solver.md). Never imports OR-Tools/CP-SAT itself — it
 * shells out to `bin/cp_sat_solver.py` (the official `ortools` PyPI
 * package, no PHP bindings exist) via `Symfony\Process`, exchanging one
 * JSON payload each way. No business logic lives here beyond translating
 * an already-fully-specified `OptimizationProblem`
 * (`CpSatPayloadBuilder`) and mapping the raw JSON result back onto
 * `OptimizationResult` — every target/weight/deviation formula was already
 * resolved by the domain before this class ever sees it.
 *
 * `solve()` owns the STRICT → PARTIAL cascade of
 * `docs/allocation-algorithm.md` §10 itself (docs/decisions.md D094): it
 * never lives in a separate orchestrator class. `OptimizationResult`
 * already carried both `strictSolverStatus` *and* `partialSolverStatus`
 * since Lot 5 — evidence the contract always intended one `solve()` call
 * to potentially perform both attempts and report a single combined
 * result. "PARTIAL" itself is not a business object: it is a solver-model
 * transformation (`unassigned[d]` slack) of the *same*
 * `OptimizationProblem`, so it can only ever be expressed inside the
 * adapter that knows how to build a CP-SAT model — never as a new domain
 * type, never by mutating `$problem`.
 */
final class OrToolsPlanningSolver implements PlanningSolver
{
    public function __construct(
        private readonly CpSatPayloadBuilder $payloadBuilder,
        private readonly UnsatDiagnosticsBuilder $diagnosticsBuilder,
        private readonly string $pythonBinary = '/opt/ortools-venv/bin/python3',
        private readonly string $scriptPath = __DIR__.'/../../bin/cp_sat_solver.py',
    ) {
    }

    public function solve(OptimizationProblem $problem): OptimizationResult
    {
        $strictRaw = $this->runProcess($this->payloadBuilder->buildSolvePayload($problem), $problem->getTimeoutSeconds());
        if (null === $strictRaw) {
            return $this->errorResult('cp_sat_solver.py produced no output or malformed JSON (STRICT).');
        }

        $strictStatus = $this->mapStatus($strictRaw['status'] ?? null);

        if (SolverStatus::ERROR === $strictStatus) {
            return $this->errorResult($strictRaw['errorMessage'] ?? 'unknown adapter error (STRICT)');
        }

        if (\in_array($strictStatus, [SolverStatus::OPTIMAL, SolverStatus::FEASIBLE], true)) {
            return $this->buildResult($strictStatus, null, CoverageStatus::COMPLETE, $strictRaw, null, $strictRaw['solveDurationMs'] ?? 0, problem: $problem, timeoutHit: (bool) ($strictRaw['timeoutHit'] ?? false));
        }

        if (SolverStatus::UNKNOWN === $strictStatus) {
            // docs/allocation-algorithm.md §10 step 5: never fall back to
            // PARTIAL on the basis of an inconclusive STRICT result — that
            // would silently treat "we don't know" as "infeasible".
            return $this->buildResult($strictStatus, null, CoverageStatus::INCOMPLETE, $strictRaw, null, $strictRaw['solveDurationMs'] ?? 0, problem: $problem, timeoutHit: (bool) ($strictRaw['timeoutHit'] ?? false));
        }

        // $strictStatus === SolverStatus::UNSATISFIABLE — the only case
        // that ever triggers PARTIAL (docs/allocation-algorithm.md §10).
        return $this->solvePartial($problem, $strictRaw);
    }

    /**
     * @param array<string, mixed> $strictRaw
     */
    private function solvePartial(OptimizationProblem $problem, array $strictRaw): OptimizationResult
    {
        $partialRaw = $this->runProcess($this->payloadBuilder->buildPartialSolvePayload($problem), $problem->getTimeoutSeconds());
        $totalDuration = ($strictRaw['solveDurationMs'] ?? 0);
        $timeoutHit = (bool) ($strictRaw['timeoutHit'] ?? false);

        if (null === $partialRaw) {
            return $this->errorResult('cp_sat_solver.py produced no output or malformed JSON (PARTIAL)', SolverStatus::UNSATISFIABLE, $totalDuration);
        }

        $totalDuration += ($partialRaw['solveDurationMs'] ?? 0);
        $timeoutHit = $timeoutHit || (bool) ($partialRaw['timeoutHit'] ?? false);
        $partialStatus = $this->mapStatus($partialRaw['status'] ?? null);
        $unassignedKeys = $partialRaw['unassignedDutyUnitKeys'] ?? [];

        if (\in_array($partialStatus, [SolverStatus::OPTIMAL, SolverStatus::FEASIBLE], true)) {
            $coverageStatus = [] === $unassignedKeys ? CoverageStatus::COMPLETE : CoverageStatus::INCOMPLETE;
            $diagnostics = null;
            if ([] !== $unassignedKeys) {
                // STRICT was UNSAT and PARTIAL still left a shortfall —
                // something to explain.
                $relaxations = $this->buildRelaxations($problem, $unassignedKeys);
                $diagnostics = $this->diagnosticsBuilder->buildForCoverageShortfall($problem, SolverStatus::UNSATISFIABLE, $partialStatus, $unassignedKeys, $relaxations);
            }

            return $this->buildResult(SolverStatus::UNSATISFIABLE, $partialStatus, $coverageStatus, $partialRaw, $diagnostics, $totalDuration, unassignedKeys: $unassignedKeys, problem: $problem, timeoutHit: $timeoutHit);
        }

        if (SolverStatus::UNSATISFIABLE === $partialStatus) {
            // docs/allocation-algorithm.md §10.5/§16, docs/decisions.md
            // D098: contract-ready, unreachable through real CP-SAT today
            // (no fixedAssignments exist yet to create a genuine
            // contradiction — see UnsatDiagnosticsBuilder's docblock).
            $conflict = new ExistingDataConflict(
                ExistingDataConflictType::LOCKED_ASSIGNMENT_CONTRADICTION,
                [],
                [],
                [],
                'Le solve PARTIAL est lui-même infaisable, indépendamment de tout nouveau choix d\'affectation — aucune donnée de verrouillage réelle n\'existe encore dans ce lot pour identifier précisément la cause (docs/decisions.md D090).',
            );
            $diagnostics = $this->diagnosticsBuilder->buildForExistingDataConflict($problem, SolverStatus::UNSATISFIABLE, $conflict);

            return $this->buildResult(SolverStatus::UNSATISFIABLE, $partialStatus, CoverageStatus::INCOMPLETE, $partialRaw, $diagnostics, $totalDuration, problem: $problem, timeoutHit: $timeoutHit);
        }

        // PARTIAL itself returned UNKNOWN or ERROR — never coerced into a
        // proven INCOMPLETE/UNSATISFIABLE (docs/decisions.md D094).
        return $this->buildResult(SolverStatus::UNSATISFIABLE, $partialStatus, CoverageStatus::INCOMPLETE, $partialRaw, null, $totalDuration, problem: $problem, timeoutHit: $timeoutHit);
    }

    /**
     * docs/allocation-algorithm.md §16, docs/decisions.md D103: only ever
     * claims a relaxation would help after actually re-solving PARTIAL
     * without the POLICY_HARD conflicts and observing a real improvement
     * — never inferred, never assumed. Today's only POLICY_HARD reason is
     * TEAM_MIN_REST (docs/decisions.md D101); if several existed, this
     * tests removing all of them *together*, not each independently — a
     * documented limitation, not a silent approximation.
     *
     * @param list<string> $unassignedKeys
     *
     * @return list<DiagnosticRelaxation>
     */
    private function buildRelaxations(OptimizationProblem $problem, array $unassignedKeys): array
    {
        $policyHardReasons = [];
        foreach ($problem->getAssignmentConflicts() as $conflict) {
            if (ConstraintTier::POLICY_HARD === $conflict->tier) {
                $policyHardReasons[$conflict->reason->value] = $conflict->reason;
            }
        }

        if ([] === $policyHardReasons) {
            return [];
        }

        $diagnosticRaw = $this->runProcess($this->payloadBuilder->buildPartialSolvePayload($problem, excludePolicyHardConflicts: true), $problem->getTimeoutSeconds());
        if (null === $diagnosticRaw) {
            return [];
        }

        $diagnosticStatus = $this->mapStatus($diagnosticRaw['status'] ?? null);
        if (!\in_array($diagnosticStatus, [SolverStatus::OPTIMAL, SolverStatus::FEASIBLE], true)) {
            return [];
        }

        $diagnosticUnassigned = $diagnosticRaw['unassignedDutyUnitKeys'] ?? [];
        if (\count($diagnosticUnassigned) >= \count($unassignedKeys)) {
            // Relaxing did not actually help in this scenario — never
            // claim it would (docs/allocation-algorithm.md §16 layer D
            // stays strictly conditional on a real, observed improvement).
            return [];
        }

        $relaxations = [];
        foreach ($policyHardReasons as $reason) {
            $phrasing = [] === $diagnosticUnassigned
                ? sprintf('La suppression de %s permettrait de retrouver une couverture complète.', $reason->value)
                : sprintf('La suppression de %s permettrait de réduire le nombre de gardes non attribuées de %d à %d.', $reason->value, \count($unassignedKeys), \count($diagnosticUnassigned));

            $relaxations[] = new DiagnosticRelaxation($reason, $phrasing);
        }

        return $relaxations;
    }

    /**
     * @param array<string, mixed> $raw
     * @param list<string>|null    $unassignedKeys
     */
    private function buildResult(
        SolverStatus $strictStatus,
        ?SolverStatus $partialStatus,
        CoverageStatus $coverageStatus,
        array $raw,
        ?UnsatDiagnostics $diagnostics,
        int $solveDurationMs,
        ?array $unassignedKeys = null,
        ?OptimizationProblem $problem = null,
        bool $timeoutHit = false,
    ): OptimizationResult {
        $assignments = array_map(
            static fn (array $a): DutyAssignmentEdge => new DutyAssignmentEdge($a['dutyUnitKey'], $a['candidateId']),
            $raw['assignments'] ?? [],
        );

        $unassignedDuties = [];
        if (null !== $unassignedKeys && null !== $problem) {
            $criticalKeys = $problem->getCoveragePolicy()->criticalDutyUnitStableKeys;
            foreach ($unassignedKeys as $key) {
                $unassignedDuties[] = new UnassignedDutyUnit($key, \in_array($key, $criticalKeys, true));
            }
        }

        [$objectiveValues, $optimality] = $this->mapPhaseResults($raw['phaseResults'] ?? []);

        $numWorkers = $problem?->getNumWorkers() ?? 1;

        return new OptimizationResult(
            $strictStatus,
            $partialStatus,
            $coverageStatus,
            $assignments,
            $unassignedDuties,
            $objectiveValues,
            $optimality,
            $diagnostics,
            null,
            new SolverMetadata(
                solverType: 'OR-Tools CP-SAT',
                solverVersion: $raw['solverVersion'] ?? '',
                solverParameterSetVersion: null,
                solveDurationMs: $solveDurationMs,
                timeoutHit: $timeoutHit,
                parameters: ['numWorkers' => $numWorkers, 'randomSeed' => 0],
            ),
        );
    }

    public function checkFeasibility(OptimizationProblem $problem, array $excludedEdges): SolverStatus
    {
        $payload = $this->payloadBuilder->buildFeasibilityPayload($problem, $excludedEdges);
        $raw = $this->runProcess($payload, $problem->getTimeoutSeconds());

        if (null === $raw) {
            return SolverStatus::ERROR;
        }

        return $this->mapStatus($raw['status'] ?? null);
    }

    /**
     * @param list<array<string, mixed>> $phaseResults
     *
     * @return array{0: array<string, float>, 1: array<string, bool>}
     */
    private function mapPhaseResults(array $phaseResults): array
    {
        $objectiveValues = [];
        $optimality = [];

        foreach ($phaseResults as $phase) {
            // A phase never attempted (the lexicographic chain stopped
            // before reaching it) is deliberately absent from both maps —
            // never a fabricated 0.0/false that would misread as "solved,
            // and not optimal" (docs/planning-solver.md §Lexicographique).
            if (true !== ($phase['attempted'] ?? false)) {
                continue;
            }

            $id = $phase['id'];
            $raw = (float) ($phase['objectiveValueScaled'] ?? 0);
            // PARTIAL_COVERAGE_CRITICAL/TOTAL (Lot 6C) are plain counts of
            // unassigned DutyUnits, and SPACING_SCORE/PREFERENCE_SATISFACTION
            // (docs/decisions.md D139) are a plain penalty sum / preference
            // count — CpSatPayloadBuilder never scales any of the three by
            // CpSatScale::SCALE (only deviation terms are: real fairness
            // quantities with fractional precision to preserve, e.g.
            // WEIGHTED_WORKLOAD's 0.01 step — these three are already exact
            // integers with no such precision to protect), so they must
            // never be divided by it here either.
            $isUnscaledCount = \in_array($id, [
                ObjectivePhaseId::PARTIAL_COVERAGE_CRITICAL->value,
                ObjectivePhaseId::PARTIAL_COVERAGE_TOTAL->value,
                ObjectivePhaseId::SPACING_SCORE->value,
                ObjectivePhaseId::PREFERENCE_SATISFACTION->value,
            ], true);
            // (float) cast first: PHP's `/` returns int when both operands
            // are int and divide evenly (e.g. 0 / SCALE), which would make
            // this a mixed int|float map instead of the float map the
            // OptimizationResult contract promises.
            $objectiveValues[$id] = $isUnscaledCount ? $raw : $raw / CpSatScale::SCALE;
            $optimality[$id] = (bool) ($phase['optimal'] ?? false);
        }

        return [$objectiveValues, $optimality];
    }

    private function mapStatus(?string $raw): SolverStatus
    {
        // docs/planning-solver.md §19: centralized mapping, one source of
        // truth (SolverStatus's own backing values — cp_sat_solver.py
        // emits the exact same strings). Anything unrecognized is ERROR,
        // never silently coerced into UNKNOWN or UNSATISFIABLE.
        return SolverStatus::tryFrom((string) $raw) ?? SolverStatus::ERROR;
    }

    /**
     * A single `runProcess()` call runs at most one base feasibility
     * Solve() plus up to 10 phases (2 PARTIAL coverage + 8 GENERATE,
     * docs/planning-solver.md §5/§24) = 11 CP-SAT `Solve()` calls, each
     * individually capped at `$timeoutSeconds` via `maxTimeInSeconds`
     * (docs/decisions.md D106). The PHP-level `Process` timeout is a
     * generous multiple of that (12x — one more than the 11-call worst
     * case) so a genuinely stuck subprocess is still eventually killed,
     * without ever cutting off a legitimate run that is simply using its
     * full CP-SAT budget on several phases in sequence.
     */
    private const PROCESS_TIMEOUT_MULTIPLIER = 12;

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>|null
     */
    private function runProcess(array $payload, ?int $timeoutSeconds = null): ?array
    {
        $process = new Process([$this->pythonBinary, $this->scriptPath]);
        // Unbounded only when no real SolverParameterSet backs this problem
        // (legacy/direct test construction — never a real production
        // OptimizationProblem, docs/decisions.md D106 resolves D093 for the
        // real path).
        $process->setTimeout(null !== $timeoutSeconds ? $timeoutSeconds * self::PROCESS_TIMEOUT_MULTIPLIER : null);
        $process->setInput(json_encode($payload, \JSON_THROW_ON_ERROR));

        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            // The subprocess itself hung well past every CP-SAT call's own
            // max_time_in_seconds — a genuine technical failure, mapped to
            // null exactly like malformed/absent output (the caller turns
            // this into SolverStatus::ERROR, never a crashed request).
            return null;
        }

        $decoded = json_decode($process->getOutput(), true);

        return \is_array($decoded) ? $decoded : null;
    }

    /**
     * @param SolverStatus|null $strictStatusOverride when set (a PARTIAL-stage
     *                                                failure), $strictSolverStatus
     *                                                stays the real, already-known
     *                                                STRICT outcome (UNSATISFIABLE)
     *                                                and $partialSolverStatus
     *                                                becomes ERROR — never
     *                                                collapsing a known STRICT
     *                                                fact into ERROR just because
     *                                                the *next* stage failed
     */
    private function errorResult(string $message, ?SolverStatus $strictStatusOverride = null, int $solveDurationMs = 0): OptimizationResult
    {
        return new OptimizationResult(
            $strictStatusOverride ?? SolverStatus::ERROR,
            null !== $strictStatusOverride ? SolverStatus::ERROR : null,
            CoverageStatus::INCOMPLETE,
            [],
            [],
            [],
            [],
            null,
            null,
            new SolverMetadata(
                solverType: 'OR-Tools CP-SAT',
                solverVersion: '',
                solverParameterSetVersion: null,
                solveDurationMs: $solveDurationMs,
                timeoutHit: false,
                parameters: ['error' => $message],
            ),
        );
    }
}
