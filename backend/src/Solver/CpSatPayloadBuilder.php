<?php

declare(strict_types=1);

namespace App\Solver;

use App\Eligibility\ConstraintTier;
use App\Eligibility\DutyUnit;
use App\Eligibility\EligibilityMatrix;
use App\Fairness\AssignmentConflict;
use App\Fairness\DutyAssignmentEdge;
use App\Fairness\FairnessDimensionKey;
use App\Fairness\ObjectivePhase;
use App\Fairness\ObjectivePhaseId;
use App\Fairness\OptimizationProblem;
use App\Fairness\SpacingPairPenalty;
use App\Service\SpacingPenaltyCalculator;

/**
 * Pure translation from `OptimizationProblem` to the JSON contract
 * `bin/cp_sat_solver.py` consumes (docs/planning-solver.md §Modèle CP-SAT).
 * Every value it emits was already computed by the domain
 * (`FairnessContext`/`OptimizationProblem`) — this class rounds/scales
 * (`CpSatScale`) and reshapes, it never derives a new business quantity.
 *
 * Deviation terms use `sourceUserStableId` (person) as the fairness unit —
 * matching `fairnessTargets`/`structurallyForcedLoad` — but the CP-SAT
 * decision variables themselves are keyed by `sourceTeamMemberStableId`
 * (stint), matching `EligibilityMatrix` and a real future
 * `DutyAssignment.teamMember` (docs/decisions.md D082). A person's term
 * therefore sums the variables of *all* of that person's stints.
 *
 * `SPACING_SCORE`/`PREFERENCE_SATISFACTION` (docs/decisions.md D139) are
 * the two exceptions to "never derives a new business quantity" above:
 * `SpacingPenaltyCalculator` genuinely computes new data (this lot closes
 * a real gap, the two phases were `NEUTRAL` — no data backed them at all).
 * Preference data itself is not new: `EligibilityResult::$preferred` is
 * reused exactly as `EligibilityService` already computes it, never
 * recomputed here.
 */
final class CpSatPayloadBuilder
{
    public function __construct(
        private readonly SpacingPenaltyCalculator $spacingPenaltyCalculator,
    ) {
    }

    /**
     * @param bool $excludePolicyHardConflicts docs/decisions.md D103: used
     *                                         only to build the diagnostic
     *                                         "what if this POLICY_HARD
     *                                         were relaxed?" solve — never
     *                                         for a real STRICT/PARTIAL
     *                                         solve, which must always
     *                                         honor every conflict
     *
     * @return array<string, mixed>
     */
    public function buildSolvePayload(OptimizationProblem $problem, bool $excludePolicyHardConflicts = false): array
    {
        $matrix = $problem->getEligibilityMatrix();
        $dutyUnitsSection = $this->buildDutyUnitsSection($problem, $matrix);
        $stintToPerson = $this->buildStintToPersonMap($matrix);
        $stintsByPerson = $this->buildStintsByPerson($stintToPerson);
        $unitsByStint = $this->buildUnitsByStint($dutyUnitsSection);

        return [
            'solveType' => 'STRICT',
            'dutyUnits' => $dutyUnitsSection,
            // No real lock/fixed-assignment concept exists on
            // OptimizationProblem yet (docs/fairness.md §10,
            // docs/decisions.md D090) — never fabricated here.
            'excludedEdges' => [],
            'conflicts' => $this->buildConflictsSection($problem, $excludePolicyHardConflicts),
            'feasibilityOnly' => false,
            'phases' => $this->buildPhasesSection($problem, $stintsByPerson, $unitsByStint),
            'numWorkers' => $problem->getNumWorkers(),
            'randomSeed' => 0,
            // Per-Solve()-call budget (docs/decisions.md D106) — null means
            // "no real SolverParameterSet behind this problem" (legacy/direct
            // test construction only, never a real production OptimizationProblem).
            'maxTimeInSeconds' => $problem->getTimeoutSeconds(),
        ];
    }

    /**
     * @return list<array{leftDutyUnitKey: string, rightDutyUnitKey: string, candidateId: string, tier: string}>
     */
    private function buildConflictsSection(OptimizationProblem $problem, bool $excludePolicyHardConflicts): array
    {
        $conflicts = $problem->getAssignmentConflicts();
        if ($excludePolicyHardConflicts) {
            $conflicts = array_values(array_filter(
                $conflicts,
                static fn (AssignmentConflict $c): bool => ConstraintTier::POLICY_HARD !== $c->tier,
            ));
        }

        // Canonical order (docs/decisions.md D100 §Déterminisme) — never
        // left to insertion order, which AssignmentConflictAnalyzer
        // already produces deterministically, but re-asserted here since
        // this is the boundary that actually gets serialized.
        usort($conflicts, static fn (AssignmentConflict $a, AssignmentConflict $b): int => [$a->leftDutyUnitStableKey, $a->rightDutyUnitStableKey, $a->candidateStableKey]
            <=> [$b->leftDutyUnitStableKey, $b->rightDutyUnitStableKey, $b->candidateStableKey]);

        return array_map(
            static fn (AssignmentConflict $c): array => [
                'leftDutyUnitKey' => $c->leftDutyUnitStableKey,
                'rightDutyUnitKey' => $c->rightDutyUnitStableKey,
                'candidateId' => $c->candidateStableKey,
                'tier' => $c->tier->value,
            ],
            $conflicts,
        );
    }

    /**
     * `OptimizationProblem` itself is never mutated or rebuilt — the
     * PARTIAL transformation (docs/allocation-algorithm.md §10: `unassigned[d]`
     * slack + the two coverage-priority phases) exists only inside this
     * JSON payload, read fresh from the same problem every time
     * (docs/decisions.md D094).
     *
     * @return array<string, mixed>
     */
    public function buildPartialSolvePayload(OptimizationProblem $problem, bool $excludePolicyHardConflicts = false): array
    {
        $strict = $this->buildSolvePayload($problem, $excludePolicyHardConflicts);

        $requiredKeys = array_map(
            static fn (DutyUnit $unit): string => $unit->getStableKey(),
            $problem->getRequiredDutyUnits(),
        );
        $criticalKeys = array_values(array_intersect($requiredKeys, $problem->getCoveragePolicy()->criticalDutyUnitStableKeys));

        $coveragePhases = [
            [
                'id' => ObjectivePhaseId::PARTIAL_COVERAGE_CRITICAL->value,
                'kind' => 'MIN_UNASSIGNED_CRITICAL',
                'direction' => 'MINIMIZE',
                'dutyUnitKeys' => $criticalKeys,
            ],
            [
                'id' => ObjectivePhaseId::PARTIAL_COVERAGE_TOTAL->value,
                'kind' => 'MIN_UNASSIGNED_TOTAL',
                'direction' => 'MINIMIZE',
                'dutyUnitKeys' => $requiredKeys,
            ],
        ];

        $strict['solveType'] = 'PARTIAL';
        $strict['phases'] = [...$coveragePhases, ...$strict['phases']];

        return $strict;
    }

    /**
     * @param list<DutyAssignmentEdge> $excludedEdges
     *
     * @return array<string, mixed>
     */
    public function buildFeasibilityPayload(OptimizationProblem $problem, array $excludedEdges): array
    {
        $matrix = $problem->getEligibilityMatrix();

        return [
            'dutyUnits' => $this->buildDutyUnitsSection($problem, $matrix),
            'excludedEdges' => array_map(
                static fn (DutyAssignmentEdge $edge): array => [
                    'dutyUnitKey' => $edge->dutyUnitStableKey,
                    'candidateId' => $edge->sourceTeamMemberStableId,
                ],
                $excludedEdges,
            ),
            'conflicts' => $this->buildConflictsSection($problem, false),
            'feasibilityOnly' => true,
            'phases' => [],
            'numWorkers' => $problem->getNumWorkers(),
            'randomSeed' => 0,
            'maxTimeInSeconds' => $problem->getTimeoutSeconds(),
        ];
    }

    /**
     * @return list<array{key: string, required: bool, eligibleCandidates: list<string>}>
     */
    private function buildDutyUnitsSection(OptimizationProblem $problem, EligibilityMatrix $matrix): array
    {
        $section = [];

        foreach ($problem->getRequiredDutyUnits() as $unit) {
            $section[] = $this->buildDutyUnitEntry($unit, $matrix, required: true);
        }

        foreach ($problem->getOptionalDutyUnits() as $unit) {
            $section[] = $this->buildDutyUnitEntry($unit, $matrix, required: false);
        }

        return $section;
    }

    /**
     * @return array{key: string, required: bool, eligibleCandidates: list<string>}
     */
    private function buildDutyUnitEntry(DutyUnit $unit, EligibilityMatrix $matrix, bool $required): array
    {
        $eligibleCandidates = [];
        foreach ($matrix->getForDutyUnit($unit) as $stintStableId => $result) {
            if ($result->eligible) {
                $eligibleCandidates[] = $stintStableId;
            }
        }

        return [
            'key' => $unit->getStableKey(),
            'required' => $required,
            'eligibleCandidates' => $eligibleCandidates,
        ];
    }

    /**
     * @return array<string, string> sourceTeamMemberStableId => sourceUserStableId
     */
    private function buildStintToPersonMap(EligibilityMatrix $matrix): array
    {
        $map = [];
        foreach ($matrix->getCandidates() as $member) {
            $map[(string) $member->getSourceTeamMemberStableId()] = (string) $member->getSourceUserStableId();
        }

        return $map;
    }

    /**
     * @param array<string, string> $stintToPerson
     *
     * @return array<string, list<string>> sourceUserStableId => list<sourceTeamMemberStableId>
     */
    private function buildStintsByPerson(array $stintToPerson): array
    {
        $byPerson = [];
        foreach ($stintToPerson as $stintId => $personId) {
            $byPerson[$personId][] = $stintId;
        }

        return $byPerson;
    }

    /**
     * @param list<array{key: string, required: bool, eligibleCandidates: list<string>}> $dutyUnitsSection
     *
     * @return array<string, list<string>> sourceTeamMemberStableId => list<dutyUnitKey>
     */
    private function buildUnitsByStint(array $dutyUnitsSection): array
    {
        $byStint = [];
        foreach ($dutyUnitsSection as $entry) {
            foreach ($entry['eligibleCandidates'] as $stintId) {
                $byStint[$stintId][] = $entry['key'];
            }
        }

        return $byStint;
    }

    /**
     * @param array<string, list<string>> $stintsByPerson
     * @param array<string, list<string>> $unitsByStint
     *
     * @return list<array{id: string, kind: string, direction: string, terms: list<array{constantOffset: int, variableCoefficients: list<array{dutyUnitKey: string, candidateId: string, coefficient: int}>}>}>
     */
    private function buildPhasesSection(OptimizationProblem $problem, array $stintsByPerson, array $unitsByStint): array
    {
        $section = [];

        foreach ($problem->getObjectivePhases() as $phase) {
            $section[] = match ($phase->id) {
                ObjectivePhaseId::SPACING_SCORE => $this->buildSpacingPhaseEntry($phase, $problem),
                ObjectivePhaseId::PREFERENCE_SATISFACTION => $this->buildPreferencePhaseEntry($phase, $problem),
                default => $this->buildDeviationPhaseEntry($phase, $problem, $stintsByPerson, $unitsByStint),
            };
        }

        return $section;
    }

    /**
     * @param array<string, list<string>> $stintsByPerson
     * @param array<string, list<string>> $unitsByStint
     *
     * @return array{id: string, kind: string, direction: string, terms: list<array{constantOffset: int, variableCoefficients: list<array{dutyUnitKey: string, candidateId: string, coefficient: int}>}>}
     */
    private function buildDeviationPhaseEntry(ObjectivePhase $phase, OptimizationProblem $problem, array $stintsByPerson, array $unitsByStint): array
    {
        $kind = $this->kindFor($phase->id);

        return [
            'id' => $phase->id->value,
            'kind' => $kind,
            'direction' => $phase->direction->value,
            'terms' => [] === $phase->dimensions || 'NEUTRAL' === $kind
                ? []
                : $this->buildDeviationTerms($problem, $phase, $stintsByPerson, $unitsByStint),
        ];
    }

    /**
     * `SPACING_SCORE` (docs/decisions.md D139) — `SpacingPenaltyCalculator`
     * finds which pairs of `DutyUnit`s should cost something if the same
     * candidate ends up with both; here, each structural pair is turned
     * into one payload term per candidate genuinely eligible for *both*
     * units (never a candidate ineligible for one of them — the Python
     * side would have no variable to build the AND-term against). The
     * objective is the **negated** penalty sum, so that `direction =
     * MAXIMIZE` (fixed by `ObjectivePhase::spacingScore()`) correctly
     * means "minimize total penalty": 0 = no penalized pair triggered
     * (best possible), a more negative value = more accumulated penalty.
     *
     * @return array{id: string, kind: string, direction: string, terms: list<array{unitAKey: string, unitBKey: string, candidateId: string, penalty: int}>}
     */
    private function buildSpacingPhaseEntry(ObjectivePhase $phase, OptimizationProblem $problem): array
    {
        $matrix = $problem->getEligibilityMatrix();
        $allUnits = [...$problem->getRequiredDutyUnits(), ...$problem->getOptionalDutyUnits()];
        $unitsByKey = [];
        foreach ($allUnits as $unit) {
            $unitsByKey[$unit->getStableKey()] = $unit;
        }

        $terms = [];
        foreach ($this->spacingPenaltyCalculator->buildPairPenalties($allUnits) as $pairPenalty) {
            \assert($pairPenalty instanceof SpacingPairPenalty);
            $unitA = $unitsByKey[$pairPenalty->unitAKey];
            $unitB = $unitsByKey[$pairPenalty->unitBKey];
            $eligibleForA = array_keys(array_filter($matrix->getForDutyUnit($unitA), static fn ($r) => $r->eligible));
            $eligibleForB = array_keys(array_filter($matrix->getForDutyUnit($unitB), static fn ($r) => $r->eligible));

            foreach (array_intersect($eligibleForA, $eligibleForB) as $candidateId) {
                $terms[] = [
                    'unitAKey' => $pairPenalty->unitAKey,
                    'unitBKey' => $pairPenalty->unitBKey,
                    'candidateId' => $candidateId,
                    'penalty' => $pairPenalty->penalty,
                ];
            }
        }

        return [
            'id' => $phase->id->value,
            'kind' => 'SPACING_PENALTY',
            'direction' => $phase->direction->value,
            'terms' => $terms,
        ];
    }

    /**
     * `PREFERENCE_SATISFACTION` (docs/decisions.md D139) — reuses
     * `EligibilityResult::$preferred` exactly as `EligibilityService`
     * already computes it: one boolean per (DutyUnit, candidate) pair,
     * never per constituent Duty (a 3-day block preferred via one
     * PREFER_DUTY day is rewarded once, never three times — see
     * `EligibilityService::evaluate()`, unchanged by this lot). Plain
     * linear sum, coefficient 1 per satisfied preference, no scaling
     * needed (an integer count, never a fractional quantity).
     *
     * @return array{id: string, kind: string, direction: string, terms: list<array{constantOffset: int, variableCoefficients: list<array{dutyUnitKey: string, candidateId: string, coefficient: int}>}>}
     */
    private function buildPreferencePhaseEntry(ObjectivePhase $phase, OptimizationProblem $problem): array
    {
        $matrix = $problem->getEligibilityMatrix();
        $variableCoefficients = [];

        foreach ([...$problem->getRequiredDutyUnits(), ...$problem->getOptionalDutyUnits()] as $unit) {
            foreach ($matrix->getForDutyUnit($unit) as $candidateId => $result) {
                if ($result->eligible && $result->preferred) {
                    $variableCoefficients[] = [
                        'dutyUnitKey' => $unit->getStableKey(),
                        'candidateId' => $candidateId,
                        'coefficient' => 1,
                    ];
                }
            }
        }

        return [
            'id' => $phase->id->value,
            'kind' => 'LINEAR',
            'direction' => $phase->direction->value,
            'terms' => [] === $variableCoefficients ? [] : [['constantOffset' => 0, 'variableCoefficients' => $variableCoefficients]],
        ];
    }

    private function kindFor(ObjectivePhaseId $id): string
    {
        return match ($id) {
            ObjectivePhaseId::MAX_DEVIATION_PRIMARY, ObjectivePhaseId::MAX_DEVIATION_SECONDARY => 'MAX_DEVIATION',
            ObjectivePhaseId::SUM_DEVIATION_PRIMARY, ObjectivePhaseId::SUM_DEVIATION_SECONDARY => 'SUM_DEVIATION',
            ObjectivePhaseId::NAMED_HOLIDAY_REPETITION_PENALTY,
            ObjectivePhaseId::DETERMINISTIC_TIE_BREAK => 'NEUTRAL',
            ObjectivePhaseId::SPACING_SCORE => 'SPACING_PENALTY',
            ObjectivePhaseId::PREFERENCE_SATISFACTION => 'LINEAR',
        };
    }

    /**
     * `normalizedDeviation(person,d) * SCALE` as a precomputed integer
     * linear term (docs/allocation-algorithm.md §5/§6,
     * docs/planning-solver.md §Scaling):
     *
     *   scale(person,d)   = max(fairnessTarget(person,d), smallestUnit(d))
     *   perUnitCoeff       = SCALE / scale(person,d)
     *   term(person,d)     = Σ_unit dimensionMembership(unit,d) * perUnitCoeff * x[unit][stint-of-person]
     *                        - (structurallyForcedLoad(person,d) + fairnessTarget(person,d)) * perUnitCoeff
     *
     * All division happens here, in PHP, against constants already known
     * before solving — never as a division *inside* CP-SAT.
     *
     * @param array<string, list<string>> $stintsByPerson
     * @param array<string, list<string>> $unitsByStint
     *
     * @return list<array{constantOffset: int, variableCoefficients: list<array{dutyUnitKey: string, candidateId: string, coefficient: int}>}>
     */
    private function buildDeviationTerms(OptimizationProblem $problem, ObjectivePhase $phase, array $stintsByPerson, array $unitsByStint): array
    {
        $terms = [];

        foreach ($stintsByPerson as $personId => $stints) {
            $target = $problem->getFairnessTarget($personId);
            $forced = $problem->getStructurallyForcedLoad($personId);

            foreach ($phase->dimensions as $dimension) {
                $terms[] = $this->buildOneTerm($problem, $dimension, $personId, $stints, $unitsByStint, $target->get($dimension), $forced->get($dimension));
            }
        }

        // Drop terms that contribute nothing at all (no variable, zero
        // constant) — never sent to CP-SAT, nothing lost: a term with no
        // variables and a zero offset is identically zero in every
        // solution.
        return array_values(array_filter(
            $terms,
            static fn (array $term): bool => [] !== $term['variableCoefficients'] || 0 !== $term['constantOffset'],
        ));
    }

    /**
     * @param list<string>                $stints
     * @param array<string, list<string>> $unitsByStint
     *
     * @return array{constantOffset: int, variableCoefficients: list<array{dutyUnitKey: string, candidateId: string, coefficient: int}>}
     */
    private function buildOneTerm(OptimizationProblem $problem, FairnessDimensionKey $dimension, string $personId, array $stints, array $unitsByStint, float $target, float $forced): array
    {
        $scaleDenom = max($target, CpSatScale::smallestUnit($dimension->type));
        $perUnitCoeff = CpSatScale::SCALE / $scaleDenom;

        $variableCoefficients = [];
        foreach ($stints as $stintId) {
            foreach ($unitsByStint[$stintId] ?? [] as $unitKey) {
                $weight = $problem->getDimensionMembership($unitKey)->get($dimension);
                if (0.0 === $weight) {
                    continue;
                }

                // NOT CpSatScale::toScaledInt() here: $perUnitCoeff already
                // embeds the SCALE factor (SCALE / scaleDenom) — applying
                // toScaledInt() on top would multiply by SCALE a second
                // time. A single round() is the correct, and only,
                // rounding step for this product.
                $coefficient = (int) round($weight * $perUnitCoeff);
                if (0 !== $coefficient) {
                    $variableCoefficients[] = [
                        'dutyUnitKey' => $unitKey,
                        'candidateId' => $stintId,
                        'coefficient' => $coefficient,
                    ];
                }
            }
        }

        return [
            'constantOffset' => (int) round(-($forced + $target) * $perUnitCoeff),
            'variableCoefficients' => $variableCoefficients,
        ];
    }
}
