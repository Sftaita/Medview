<?php

declare(strict_types=1);

namespace App\Tests\Solver;

use App\Eligibility\EligibilityMatrix;
use App\Entity\TeamMemberRole;
use App\Entity\UserAvailabilityPeriod;
use App\Entity\UserAvailabilityType;
use App\Fairness\CoveragePolicy;
use App\Fairness\DutyAssignmentEdge;
use App\Fairness\FairnessDimensionValues;
use App\Fairness\ObjectivePhaseId;
use App\Fairness\OptimizationMode;
use App\Fairness\OptimizationProblem;
use App\Fairness\SolverStatus;
use App\Repository\PlanningLineRepository;
use App\Service\DutyMaterializationService;
use App\Service\EligibilityMatrixBuilder;
use App\Service\FairnessContextBuilder;
use App\Service\OptimizationProblemBuilder;
use App\Service\PlanningRuleSetService;
use App\Service\PlanningService;
use App\Service\PlanningSnapshotService;
use App\Service\PlanningTeamMembershipService;
use App\Service\UnsatDiagnosticsBuilder;
use App\Solver\CpSatPayloadBuilder;
use App\Solver\OrToolsPlanningSolver;
use App\Tests\PlanningDomainTestHelpers;
use App\Tests\PlanningGenerationTestHelpers;
use App\Tests\PlanningTestHelpers;
use App\Tests\SolverTestHelpers;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class OrToolsPlanningSolverTest extends KernelTestCase
{
    use PlanningDomainTestHelpers;
    use PlanningGenerationTestHelpers;
    use PlanningTestHelpers;
    use SolverTestHelpers;

    // --- Solve simple -------------------------------------------------

    public function testOneDutyOneCandidateSolvesOptimalWithThatCandidateAssigned(): void
    {
        [$solver, $problem, $ids] = $this->scenarioSingleDutySingleCandidate();

        $result = $solver->solve($problem);

        self::assertSame(SolverStatus::OPTIMAL, $result->strictSolverStatus);
        self::assertCount(1, $result->assignments);
        self::assertSame($ids['duty'], $result->assignments[0]->dutyUnitStableKey);
        self::assertSame($ids['member'], $result->assignments[0]->sourceTeamMemberStableId);
    }

    public function testOneDutyTwoCandidatesAssignsExactlyOne(): void
    {
        [$solver, $problem] = $this->scenarioSingleDutyTwoCandidates();

        $result = $solver->solve($problem);

        self::assertSame(SolverStatus::OPTIMAL, $result->strictSolverStatus);
        self::assertCount(1, $result->assignments);
    }

    public function testTwoDutiesTwoCandidatesProducesACompleteSolution(): void
    {
        [$solver, $problem, $ids] = $this->scenarioTwoDutiesTwoCandidates();

        $result = $solver->solve($problem);

        self::assertSame(SolverStatus::OPTIMAL, $result->strictSolverStatus);
        self::assertCount(2, $result->assignments);
        $assignedUnits = array_map(static fn (DutyAssignmentEdge $e) => $e->dutyUnitStableKey, $result->assignments);
        sort($assignedUnits);
        $expected = [$ids['duty1'], $ids['duty2']];
        sort($expected);
        self::assertSame($expected, $assignedUnits);
    }

    public function testNoEligibleCandidateForARequiredDutyIsUnsatisfiable(): void
    {
        [$solver, $problem] = $this->scenarioRequiredDutyWithNoCandidate();

        $result = $solver->solve($problem);

        self::assertSame(SolverStatus::UNSATISFIABLE, $result->strictSolverStatus);
        self::assertSame([], $result->assignments);
    }

    // --- Fairness -------------------------------------------------------

    public function testBalancedDistributionIsPreferredOnMaxDeviationSecondary(): void
    {
        [$solver, $problem] = $this->scenarioFourDutiesTwoEquallyExposedCandidates();

        $result = $solver->solve($problem);

        self::assertSame(SolverStatus::OPTIMAL, $result->strictSolverStatus);
        $counts = [];
        foreach ($result->assignments as $edge) {
            $counts[$edge->sourceTeamMemberStableId] = ($counts[$edge->sourceTeamMemberStableId] ?? 0) + 1;
        }
        self::assertCount(2, $counts, 'both equally-exposed candidates must receive at least one duty');
        foreach ($counts as $count) {
            self::assertSame(2, $count, 'four duties split between two equally-exposed candidates must be exactly 2/2, minimizing max deviation');
        }
    }

    public function testSecondaryPhasesActuallyOptimizeWithRealDimensions(): void
    {
        [$solver, $problem] = $this->scenarioFourDutiesTwoEquallyExposedCandidates();

        $result = $solver->solve($problem);

        self::assertTrue($result->optimality[ObjectivePhaseId::MAX_DEVIATION_SECONDARY->value]);
        self::assertTrue($result->optimality[ObjectivePhaseId::SUM_DEVIATION_SECONDARY->value]);
        self::assertArrayHasKey(ObjectivePhaseId::MAX_DEVIATION_SECONDARY->value, $result->objectiveValues);
        self::assertArrayHasKey(ObjectivePhaseId::SUM_DEVIATION_SECONDARY->value, $result->objectiveValues);
    }

    public function testPrimaryPhasesAreNeutralAndFabricateNothing(): void
    {
        [$solver, $problem] = $this->scenarioFourDutiesTwoEquallyExposedCandidates();

        $result = $solver->solve($problem);

        // docs/decisions.md D086: PRIMARY is always empty today
        // (WEEKEND_GROUPS/NAMED_HOLIDAY are not implemented dimensions) —
        // both PRIMARY phases must be trivially satisfied, never
        // fabricated data, never omitted from the result.
        self::assertSame(0.0, $result->objectiveValues[ObjectivePhaseId::MAX_DEVIATION_PRIMARY->value]);
        self::assertSame(0.0, $result->objectiveValues[ObjectivePhaseId::SUM_DEVIATION_PRIMARY->value]);
        self::assertTrue($result->optimality[ObjectivePhaseId::MAX_DEVIATION_PRIMARY->value]);
        self::assertTrue($result->optimality[ObjectivePhaseId::SUM_DEVIATION_PRIMARY->value]);
    }

    // --- Lexicographic ---------------------------------------------------

    public function testFinalAssignmentActuallyAchievesTheLockedMaxDeviationValue(): void
    {
        [$solver, $problem] = $this->scenarioFourDutiesTwoEquallyExposedCandidates();

        $result = $solver->solve($problem);

        // Independently recompute maxDeviation(SECONDARY) from the actual
        // final assignment — proves phase 4 (sumDeviation) never degraded
        // the value phase 3 (maxDeviation) already proved optimal
        // (docs/planning-solver.md §Lexicographique).
        $countsByCandidate = [];
        foreach ($result->assignments as $edge) {
            $countsByCandidate[$edge->sourceTeamMemberStableId] = ($countsByCandidate[$edge->sourceTeamMemberStableId] ?? 0) + 1;
        }
        $maxCount = max($countsByCandidate);
        $minCount = min($countsByCandidate);

        self::assertLessThanOrEqual(1, $maxCount - $minCount, 'the final assignment must still realize the balanced (max-deviation-minimizing) split');
    }

    public function testStillGenuinelyUnimplementedPhasesNeverDegradeAnything(): void
    {
        [$solver, $problem] = $this->scenarioFourDutiesTwoEquallyExposedCandidates();

        $result = $solver->solve($problem);

        // NAMED_HOLIDAY_REPETITION_PENALTY: no holiday calendar exists at
        // all (docs/decisions.md D104) — genuinely neutral. DETERMINISTIC_TIE_BREAK:
        // no real seed material is consumed by the solve (D088) — genuinely
        // neutral. Neither is affected by docs/decisions.md D139.
        foreach ([ObjectivePhaseId::NAMED_HOLIDAY_REPETITION_PENALTY, ObjectivePhaseId::DETERMINISTIC_TIE_BREAK] as $id) {
            self::assertSame(0.0, $result->objectiveValues[$id->value], sprintf('%s must be neutral (no real data backs it in this lot)', $id->value));
            self::assertTrue($result->optimality[$id->value], sprintf('%s must be trivially "optimal" as a neutral phase', $id->value));
        }
    }

    public function testPreferenceSatisfactionIsGenuinelyZeroWhenNoOnePreferredAnything(): void
    {
        // docs/decisions.md D139: this scenario declares no PREFER_DUTY at
        // all — 0 is now the true, computed answer (an empty, genuinely
        // solved objective), never the old "no real data" placeholder.
        [$solver, $problem] = $this->scenarioFourDutiesTwoEquallyExposedCandidates();

        $result = $solver->solve($problem);

        self::assertSame(0.0, $result->objectiveValues[ObjectivePhaseId::PREFERENCE_SATISFACTION->value]);
        self::assertTrue($result->optimality[ObjectivePhaseId::PREFERENCE_SATISFACTION->value]);
    }

    public function testSpacingScoreIsNowARealAttemptedAndProvenPhase(): void
    {
        // docs/decisions.md D139: with 4 consecutive daily duties split
        // 2/2 between 2 candidates, some adjacency is structurally
        // unavoidable — the point of this test is only that the phase is
        // genuinely attempted and solved to proven optimality, never that
        // it stays at the old neutral 0.0. Exact tiering values are
        // covered by dedicated spacing scenarios below.
        [$solver, $problem] = $this->scenarioFourDutiesTwoEquallyExposedCandidates();

        $result = $solver->solve($problem);

        self::assertArrayHasKey(ObjectivePhaseId::SPACING_SCORE->value, $result->objectiveValues);
        self::assertTrue($result->optimality[ObjectivePhaseId::SPACING_SCORE->value]);
        self::assertLessThanOrEqual(0.0, $result->objectiveValues[ObjectivePhaseId::SPACING_SCORE->value], 'SPACING_SCORE is a negated penalty sum — 0 or negative, never positive');
    }

    // --- Spacing (docs/decisions.md D139) ---------------------------------

    public function testAvoidableAdjacencyIsAvoidedWhenFairnessTiesAcrossAllPairings(): void
    {
        // 3 equal-weight isolated duties, 2 candidates eligible for all
        // three: fairness (2/1 split) is mathematically identical no
        // matter *which* 2 of the 3 go together (docs/decisions.md D139
        // Test A/B) — only SPACING_SCORE can tell the 3 possible pairings
        // apart. Feb 1 and Feb 2 are adjacent (freeDays = 0); Feb 15 is far
        // from both.
        [$solver, $problem, $units] = $this->scenarioIsolatedDuties(['2027-02-01', '2027-02-02', '2027-02-15']);

        $result = $solver->solve($problem);

        self::assertSame(SolverStatus::OPTIMAL, $result->strictSolverStatus);
        $unitsByCandidate = [];
        foreach ($result->assignments as $edge) {
            $unitsByCandidate[$edge->sourceTeamMemberStableId][] = $edge->dutyUnitStableKey;
        }
        foreach ($unitsByCandidate as $candidateUnits) {
            $hasBoth = \in_array($units['2027-02-01'], $candidateUnits, true) && \in_array($units['2027-02-02'], $candidateUnits, true);
            self::assertFalse($hasBoth, 'the two adjacent duties (freeDays = 0) must never land on the same candidate when an equally-fair alternative exists');
        }
    }

    public function testSpacingNeverBlocksCoverageWhenNoAlternativeExists(): void
    {
        // docs/decisions.md D139 Test C: a single candidate is eligible for
        // two adjacent REQUIRED duties (the other is UNAVAILABLE both
        // days) — SPACING_SCORE stays SOFT: coverage must remain COMPLETE,
        // never UNSAT because of spacing.
        [$solver, $problem] = $this->scenarioIsolatedDuties(
            ['2027-02-01', '2027-02-02'],
            unavailableByDate: ['2027-02-01' => 1, '2027-02-02' => 1],
        );

        $result = $solver->solve($problem);

        self::assertSame(SolverStatus::OPTIMAL, $result->strictSolverStatus);
        self::assertCount(2, $result->assignments, 'both duties must still be covered even though they are adjacent for the only eligible candidate');
    }

    public function testSpacingNeverReopensTheFairnessBalanceAlreadyLocked(): void
    {
        // docs/decisions.md D139 Test F: 4 consecutive daily duties split
        // between 2 equally-exposed candidates — some adjacency is
        // topologically unavoidable in *any* 2/2 split, and concentrating
        // more duties on one candidate (3/1, 4/0) would only ever create
        // *more* adjacent pairs, never fewer — so SPACING_SCORE (a later,
        // lower-priority phase) can never have a real incentive to reopen
        // the 2/2 balance MAX_DEVIATION_SECONDARY already proved optimal.
        // This asserts that lock genuinely holds once a real SPACING_SCORE
        // phase runs after it.
        [$solver, $problem] = $this->scenarioFourDutiesTwoEquallyExposedCandidates();

        $result = $solver->solve($problem);

        $countsByCandidate = [];
        foreach ($result->assignments as $edge) {
            $countsByCandidate[$edge->sourceTeamMemberStableId] = ($countsByCandidate[$edge->sourceTeamMemberStableId] ?? 0) + 1;
        }
        self::assertCount(2, $countsByCandidate);
        foreach ($countsByCandidate as $count) {
            self::assertSame(2, $count, 'fairness (an earlier phase) must never be sacrificed to improve SPACING_SCORE');
        }
    }

    // --- Preferences (docs/decisions.md D139) -----------------------------

    public function testPreferenceDecidesAmongOtherwiseTiedCandidates(): void
    {
        // docs/decisions.md D139 Test G: two duties far enough apart that
        // spacing is irrelevant, 2 candidates equally eligible for both —
        // fairness (1/1) ties every pairing. Only candidate A prefers the
        // first duty.
        [$solver, $problem, $units, $memberA] = $this->scenarioIsolatedDuties(
            ['2027-02-01', '2027-03-01'],
            preferDutyByDate: ['2027-02-01' => 0],
        );

        $result = $solver->solve($problem);

        $assignmentsByUnit = [];
        foreach ($result->assignments as $edge) {
            $assignmentsByUnit[$edge->dutyUnitStableKey] = $edge->sourceTeamMemberStableId;
        }
        self::assertSame($memberA, $assignmentsByUnit[$units['2027-02-01']], 'the preferred unit must go to the candidate who actually preferred it');
    }

    public function testFairnessIsNeverSacrificedForAPreference(): void
    {
        // docs/decisions.md D139 Test H: candidate A prefers *both* duties
        // — if PREFERENCE_SATISFACTION could override fairness, A might
        // get both (2/0). It never does: MAX_DEVIATION_SECONDARY (an
        // earlier phase) already locked the balanced 1/1 split.
        [$solver, $problem] = $this->scenarioIsolatedDuties(
            ['2027-02-01', '2027-03-01'],
            preferDutyByDate: ['2027-02-01' => 0, '2027-03-01' => 0],
        );

        $result = $solver->solve($problem);

        $countsByCandidate = [];
        foreach ($result->assignments as $edge) {
            $countsByCandidate[$edge->sourceTeamMemberStableId] = ($countsByCandidate[$edge->sourceTeamMemberStableId] ?? 0) + 1;
        }
        self::assertCount(2, $countsByCandidate, 'both candidates must still receive one duty despite A preferring both');
        foreach ($countsByCandidate as $count) {
            self::assertSame(1, $count);
        }
    }

    public function testSpacingIsNeverSacrificedForAPreference(): void
    {
        // docs/decisions.md D139 Test I: candidate A prefers *both* of the
        // two adjacent duties. SPACING_SCORE (phase 6) is solved and
        // locked before PREFERENCE_SATISFACTION (phase 7) is ever
        // considered — the adjacent pair must never land on the same
        // candidate, even though A would prefer exactly that.
        [$solver, $problem, $units] = $this->scenarioIsolatedDuties(
            ['2027-02-01', '2027-02-02', '2027-02-15'],
            preferDutyByDate: ['2027-02-01' => 0, '2027-02-02' => 0],
        );

        $result = $solver->solve($problem);

        $unitsByCandidate = [];
        foreach ($result->assignments as $edge) {
            $unitsByCandidate[$edge->sourceTeamMemberStableId][] = $edge->dutyUnitStableKey;
        }
        foreach ($unitsByCandidate as $candidateUnits) {
            $hasBoth = \in_array($units['2027-02-01'], $candidateUnits, true) && \in_array($units['2027-02-02'], $candidateUnits, true);
            self::assertFalse($hasBoth, 'SPACING_SCORE (an earlier phase) must never be reopened to satisfy more preferences');
        }
    }

    public function testAPreferenceOnOneComponentOfABlockRewardsTheWholeUnitExactlyOnce(): void
    {
        // docs/decisions.md D139 Test J: a single candidate (structurally
        // forced onto the block, the only real way to make the outcome
        // deterministic without depending on a fairness tie-break) prefers
        // only the block's *first* day — EligibilityService already
        // computes $preferred once per DutyUnit (unchanged by this lot);
        // this proves the reward genuinely follows that same rule end to
        // end, never once per constituent Duty.
        self::bootKernel();
        $c = self::getContainer();
        $em = $c->get(EntityManagerInterface::class);
        $planningService = $c->get(PlanningService::class);
        $lineRepository = $c->get(PlanningLineRepository::class);
        $membershipService = $c->get(PlanningTeamMembershipService::class);
        $ruleSetService = $c->get(PlanningRuleSetService::class);
        $dutyMaterialization = $c->get(DutyMaterializationService::class);
        $snapshotService = $c->get(PlanningSnapshotService::class);
        $matrixBuilder = $c->get(EligibilityMatrixBuilder::class);
        $contextBuilder = $c->get(FairnessContextBuilder::class);
        $problemBuilder = $c->get(OptimizationProblemBuilder::class);
        $solver = $c->get(OrToolsPlanningSolver::class);

        $creator = $this->createUser($em);
        $planning = $this->createPlanning($planningService, $creator, 'Team');
        $line = $lineRepository->findByPlanning($planning)[0];
        $team = $line->getPlanningTeam();
        $planningPeriod = $line->getPlanningPeriod();
        $this->activateRuleSet($ruleSetService, $team);

        $userA = $this->createUser($em);
        $this->addMember($membershipService, $team, $userA, TeamMemberRole::MEMBER, '2027-01-01', 1.0);

        $em->persist(new UserAvailabilityPeriod(
            $userA,
            UserAvailabilityType::PREFER_DUTY,
            new \DateTimeImmutable('2027-02-05 00:00', new \DateTimeZone('Europe/Brussels')),
            new \DateTimeImmutable('2027-02-06 00:00', new \DateTimeZone('Europe/Brussels')),
        ));
        $em->flush();

        $this->createTwoDutyGroup($em, $dutyMaterialization, $team, $planningPeriod, '2027-02-05', '2027-02-05 08:00', '2027-02-06 08:00', '2027-02-06 08:00', '2027-02-07 08:00');

        $problem = $this->buildOptimizationProblem($em, $snapshotService, $matrixBuilder, $contextBuilder, $problemBuilder, $planningPeriod);

        $result = $solver->solve($problem);

        self::assertSame(SolverStatus::OPTIMAL, $result->strictSolverStatus);
        self::assertCount(1, $result->assignments, 'the block is one atomic assignment decision, never two');
        self::assertSame(1.0, $result->objectiveValues[ObjectivePhaseId::PREFERENCE_SATISFACTION->value], 'a preference covering one day of a block rewards the unit\'s single assignment exactly once, never once per constituent day');
    }

    /**
     * Shared fixture for the spacing/preference tests: N standalone,
     * equal-weight (1 Duty each) REQUIRED duties, 2 candidates. Equal
     * weight everywhere is deliberate — it is what makes the fairness
     * phases (MAX/SUM_DEVIATION_SECONDARY) genuinely blind to *which*
     * duties end up paired on the same candidate, so only SPACING_SCORE/
     * PREFERENCE_SATISFACTION can tell otherwise-tied solutions apart
     * (docs/decisions.md D139).
     *
     * @param list<string>       $dates              "YYYY-MM-DD"
     * @param array<string, int> $preferDutyByDate   date => 0 (candidate A) | 1 (candidate B)
     * @param array<string, int> $unavailableByDate  date => 0 (candidate A) | 1 (candidate B)
     *
     * @return array{0: OrToolsPlanningSolver, 1: OptimizationProblem, 2: array<string, string>, 3: string, 4: string} solver, problem, dutyUnitStableKey by date, memberA stableId, memberB stableId
     */
    private function scenarioIsolatedDuties(array $dates, array $preferDutyByDate = [], array $unavailableByDate = []): array
    {
        self::bootKernel();
        $c = self::getContainer();
        $em = $c->get(EntityManagerInterface::class);
        $planningService = $c->get(PlanningService::class);
        $lineRepository = $c->get(PlanningLineRepository::class);
        $membershipService = $c->get(PlanningTeamMembershipService::class);
        $ruleSetService = $c->get(PlanningRuleSetService::class);
        $dutyMaterialization = $c->get(DutyMaterializationService::class);
        $snapshotService = $c->get(PlanningSnapshotService::class);
        $matrixBuilder = $c->get(EligibilityMatrixBuilder::class);
        $contextBuilder = $c->get(FairnessContextBuilder::class);
        $problemBuilder = $c->get(OptimizationProblemBuilder::class);
        $solver = $c->get(OrToolsPlanningSolver::class);

        $creator = $this->createUser($em);
        $planning = $this->createPlanning($planningService, $creator, 'Team');
        $line = $lineRepository->findByPlanning($planning)[0];
        $team = $line->getPlanningTeam();
        $planningPeriod = $line->getPlanningPeriod();
        $this->activateRuleSet($ruleSetService, $team);

        $userA = $this->createUser($em);
        $userB = $this->createUser($em);
        $memberA = $this->addMember($membershipService, $team, $userA, TeamMemberRole::MEMBER, '2027-01-01', 1.0);
        $memberB = $this->addMember($membershipService, $team, $userB, TeamMemberRole::MEMBER, '2027-01-01', 1.0);
        $users = [$userA, $userB];

        foreach ($this->mergeAdjacentDates($preferDutyByDate) as [$startDate, $endDate, $userIndex]) {
            $em->persist(new UserAvailabilityPeriod(
                $users[$userIndex],
                UserAvailabilityType::PREFER_DUTY,
                new \DateTimeImmutable("{$startDate} 00:00", new \DateTimeZone('Europe/Brussels')),
                (new \DateTimeImmutable("{$endDate} 00:00", new \DateTimeZone('Europe/Brussels')))->modify('+1 day'),
            ));
        }
        foreach ($this->mergeAdjacentDates($unavailableByDate) as [$startDate, $endDate, $userIndex]) {
            $em->persist(new UserAvailabilityPeriod(
                $users[$userIndex],
                UserAvailabilityType::UNAVAILABLE,
                new \DateTimeImmutable("{$startDate} 00:00", new \DateTimeZone('Europe/Brussels')),
                (new \DateTimeImmutable("{$endDate} 00:00", new \DateTimeZone('Europe/Brussels')))->modify('+1 day'),
            ));
        }
        $em->flush();

        $dutyType = $this->createDutyType($em, $team);
        $unitKeyByDate = [];
        foreach ($dates as $date) {
            $duty = $this->createDuty($dutyMaterialization, $planningPeriod, $dutyType, "{$date} 08:00", "{$date} 20:00");
            $unitKeyByDate[$date] = (string) $duty->getStableId();
        }

        $problem = $this->buildOptimizationProblem($em, $snapshotService, $matrixBuilder, $contextBuilder, $problemBuilder, $planningPeriod);

        return [$solver, $problem, $unitKeyByDate, (string) $memberA->getStableId(), (string) $memberB->getStableId()];
    }

    /**
     * Merges consecutive calendar dates of the same user into a single
     * range — `user_availability_periods` has a real, correct exclusion
     * constraint against overlapping ranges *for the same user and type*,
     * and two whole-day periods that end exactly where the next begins
     * are "touching" under its inclusive-bounds range comparison. Two
     * separate one-day periods for the same person on consecutive dates
     * would violate it; one merged period expresses the identical
     * business fact without ever hitting that boundary.
     *
     * @param array<string, int> $byDate date => userIndex
     *
     * @return list<array{0: string, 1: string, 2: int}> [startDate, endDate, userIndex], both inclusive
     */
    private function mergeAdjacentDates(array $byDate): array
    {
        $byUser = [];
        foreach ($byDate as $date => $userIndex) {
            $byUser[$userIndex][] = $date;
        }

        $ranges = [];
        foreach ($byUser as $userIndex => $dates) {
            sort($dates);
            $rangeStart = null;
            $rangeEnd = null;
            foreach ($dates as $date) {
                if (null === $rangeStart) {
                    $rangeStart = $rangeEnd = $date;
                    continue;
                }
                $nextDay = (new \DateTimeImmutable($rangeEnd))->modify('+1 day')->format('Y-m-d');
                if ($date === $nextDay) {
                    $rangeEnd = $date;
                    continue;
                }
                $ranges[] = [$rangeStart, $rangeEnd, $userIndex];
                $rangeStart = $rangeEnd = $date;
            }
            if (null !== $rangeStart) {
                $ranges[] = [$rangeStart, $rangeEnd, $userIndex];
            }
        }

        return $ranges;
    }

    // --- Statuses ---------------------------------------------------------

    public function testUnknownStatusIsNeverMappedToUnsatisfiable(): void
    {
        $solver = $this->buildSolverWithFixtureScript('echo_unknown.py');
        $problem = $this->emptyProblem();

        $result = $solver->solve($problem);

        self::assertSame(SolverStatus::UNKNOWN, $result->strictSolverStatus);
        self::assertNotSame(SolverStatus::UNSATISFIABLE, $result->strictSolverStatus);
    }

    public function testMalformedSubprocessOutputMapsToError(): void
    {
        $solver = $this->buildSolverWithFixtureScript('echo_malformed.py');
        $problem = $this->emptyProblem();

        $result = $solver->solve($problem);

        self::assertSame(SolverStatus::ERROR, $result->strictSolverStatus);
    }

    // --- checkFeasibility ---------------------------------------------------

    public function testCheckFeasibilityReturnsAFeasibleStatusWhenSolvable(): void
    {
        [$solver, $problem] = $this->scenarioSingleDutyTwoCandidates();

        $status = $solver->checkFeasibility($problem, []);

        self::assertContains($status, [SolverStatus::OPTIMAL, SolverStatus::FEASIBLE]);
    }

    public function testCheckFeasibilityReturnsUnsatisfiableWhenExcludingTheOnlyCandidateEdge(): void
    {
        [$solver, $problem, $ids] = $this->scenarioSingleDutySingleCandidate();

        $status = $solver->checkFeasibility($problem, [
            new DutyAssignmentEdge($ids['duty'], $ids['member']),
        ]);

        self::assertSame(SolverStatus::UNSATISFIABLE, $status);
    }

    public function testCheckFeasibilityWithMultipleExcludedEdgesStillWorks(): void
    {
        [$solver, $problem, $ids] = $this->scenarioTwoDutiesTwoCandidates();

        $status = $solver->checkFeasibility($problem, [
            new DutyAssignmentEdge($ids['duty1'], $ids['member1']),
            new DutyAssignmentEdge($ids['duty2'], $ids['member2']),
        ]);

        // duty1 can still go to member2, duty2 to member1 — still feasible.
        self::assertContains($status, [SolverStatus::OPTIMAL, SolverStatus::FEASIBLE]);
    }

    public function testCheckFeasibilityNeverMutatesTheProblem(): void
    {
        [$solver, $problem] = $this->scenarioSingleDutyTwoCandidates();
        $requiredBefore = $problem->getRequiredDutyUnits();

        $solver->checkFeasibility($problem, []);

        self::assertSame($requiredBefore, $problem->getRequiredDutyUnits());
    }

    // --- Reproducibility ---------------------------------------------------

    public function testSameProblemSolvedTwiceProducesTheSameResult(): void
    {
        [$solver, $problem] = $this->scenarioFourDutiesTwoEquallyExposedCandidates();

        $first = $solver->solve($problem);
        $second = $solver->solve($problem);

        self::assertSame($first->strictSolverStatus, $second->strictSolverStatus);
        self::assertSame($first->objectiveValues, $second->objectiveValues);

        $firstAssignments = $this->normalizeAssignments($first->assignments);
        $secondAssignments = $this->normalizeAssignments($second->assignments);
        self::assertSame($firstAssignments, $secondAssignments);
    }

    // --- Adversarial ---------------------------------------------------

    public function testCandidateWithNoEligibleEdgeAnywhereDoesNotBreakTheSolve(): void
    {
        self::bootKernel();
        $c = self::getContainer();
        $em = $c->get(EntityManagerInterface::class);
        $planningService = $c->get(PlanningService::class);
        $lineRepository = $c->get(PlanningLineRepository::class);
        $membershipService = $c->get(PlanningTeamMembershipService::class);
        $ruleSetService = $c->get(PlanningRuleSetService::class);
        $dutyMaterialization = $c->get(DutyMaterializationService::class);
        $snapshotService = $c->get(PlanningSnapshotService::class);
        $matrixBuilder = $c->get(EligibilityMatrixBuilder::class);
        $contextBuilder = $c->get(FairnessContextBuilder::class);
        $problemBuilder = $c->get(OptimizationProblemBuilder::class);
        $solver = $c->get(OrToolsPlanningSolver::class);

        $creator = $this->createUser($em);
        $planning = $this->createPlanning($planningService, $creator, 'Seniors');
        $line = $lineRepository->findByPlanning($planning)[0];
        $team = $line->getPlanningTeam();
        $planningPeriod = $line->getPlanningPeriod();
        $this->activateRuleSet($ruleSetService, $team);

        $activeUser = $this->createUser($em);
        $this->addMember($membershipService, $team, $activeUser, TeamMemberRole::MEMBER, '2027-01-01', 1.0);
        // Joins after the period ends -> zero structural exposure, zero
        // eligible edges anywhere, but still a real fairness candidate
        // with target = 0 (docs/fairness.md §7).
        $noExposureUser = $this->createUser($em);
        $this->addMember($membershipService, $team, $noExposureUser, TeamMemberRole::MEMBER, '2027-04-30', 1.0);

        $dutyType = $this->createDutyType($em, $team);
        $this->createDuty($dutyMaterialization, $planningPeriod, $dutyType, '2027-02-01 08:00', '2027-02-01 20:00');

        $problem = $this->buildOptimizationProblem($em, $snapshotService, $matrixBuilder, $contextBuilder, $problemBuilder, $planningPeriod);

        $result = $solver->solve($problem);

        self::assertSame(SolverStatus::OPTIMAL, $result->strictSolverStatus);
        self::assertCount(1, $result->assignments);
    }

    public function testMultipleSecondaryDimensionsAreAllConsidered(): void
    {
        self::bootKernel();
        $c = self::getContainer();
        $em = $c->get(EntityManagerInterface::class);
        $planningService = $c->get(PlanningService::class);
        $lineRepository = $c->get(PlanningLineRepository::class);
        $membershipService = $c->get(PlanningTeamMembershipService::class);
        $ruleSetService = $c->get(PlanningRuleSetService::class);
        $dutyMaterialization = $c->get(DutyMaterializationService::class);
        $snapshotService = $c->get(PlanningSnapshotService::class);
        $matrixBuilder = $c->get(EligibilityMatrixBuilder::class);
        $contextBuilder = $c->get(FairnessContextBuilder::class);
        $problemBuilder = $c->get(OptimizationProblemBuilder::class);
        $solver = $c->get(OrToolsPlanningSolver::class);

        $creator = $this->createUser($em);
        $planning = $this->createPlanning($planningService, $creator, 'Seniors');
        $line = $lineRepository->findByPlanning($planning)[0];
        $team = $line->getPlanningTeam();
        $planningPeriod = $line->getPlanningPeriod();
        $this->activateRuleSet($ruleSetService, $team);

        $userA = $this->createUser($em);
        $userB = $this->createUser($em);
        $this->addMember($membershipService, $team, $userA, TeamMemberRole::MEMBER, '2027-01-01', 1.0);
        $this->addMember($membershipService, $team, $userB, TeamMemberRole::MEMBER, '2027-01-01', 1.0);

        $dutyType = $this->createDutyType($em, $team);
        // 2027-02-05 = Friday, 2027-02-06 = Saturday, 2027-02-07 = Sunday.
        $this->createDuty($dutyMaterialization, $planningPeriod, $dutyType, '2027-02-05 08:00', '2027-02-05 20:00');
        $this->createDuty($dutyMaterialization, $planningPeriod, $dutyType, '2027-02-06 08:00', '2027-02-06 20:00');
        $this->createDuty($dutyMaterialization, $planningPeriod, $dutyType, '2027-02-07 08:00', '2027-02-07 20:00');

        $problem = $this->buildOptimizationProblem($em, $snapshotService, $matrixBuilder, $contextBuilder, $problemBuilder, $planningPeriod);
        $result = $solver->solve($problem);

        self::assertSame(SolverStatus::OPTIMAL, $result->strictSolverStatus);
        self::assertCount(3, $result->assignments);
    }

    // --- scenario builders ---------------------------------------------------

    /**
     * @return array{0: OrToolsPlanningSolver, 1: OptimizationProblem, 2: array<string,string>}
     */
    private function scenarioSingleDutySingleCandidate(): array
    {
        self::bootKernel();
        $c = self::getContainer();
        $em = $c->get(EntityManagerInterface::class);
        $planningService = $c->get(PlanningService::class);
        $lineRepository = $c->get(PlanningLineRepository::class);
        $membershipService = $c->get(PlanningTeamMembershipService::class);
        $ruleSetService = $c->get(PlanningRuleSetService::class);
        $dutyMaterialization = $c->get(DutyMaterializationService::class);
        $snapshotService = $c->get(PlanningSnapshotService::class);
        $matrixBuilder = $c->get(EligibilityMatrixBuilder::class);
        $contextBuilder = $c->get(FairnessContextBuilder::class);
        $problemBuilder = $c->get(OptimizationProblemBuilder::class);
        $solver = $c->get(OrToolsPlanningSolver::class);

        $creator = $this->createUser($em);
        $planning = $this->createPlanning($planningService, $creator, 'Seniors');
        $line = $lineRepository->findByPlanning($planning)[0];
        $team = $line->getPlanningTeam();
        $planningPeriod = $line->getPlanningPeriod();
        $this->activateRuleSet($ruleSetService, $team);

        $user = $this->createUser($em);
        $member = $this->addMember($membershipService, $team, $user, TeamMemberRole::MEMBER, '2027-01-01', 1.0);
        $dutyType = $this->createDutyType($em, $team);
        $duty = $this->createDuty($dutyMaterialization, $planningPeriod, $dutyType, '2027-02-01 08:00', '2027-02-01 20:00');

        $problem = $this->buildOptimizationProblem($em, $snapshotService, $matrixBuilder, $contextBuilder, $problemBuilder, $planningPeriod);

        return [$solver, $problem, ['duty' => (string) $duty->getStableId(), 'member' => (string) $member->getStableId()]];
    }

    /**
     * @return array{0: OrToolsPlanningSolver, 1: OptimizationProblem}
     */
    private function scenarioSingleDutyTwoCandidates(): array
    {
        self::bootKernel();
        $c = self::getContainer();
        $em = $c->get(EntityManagerInterface::class);
        $planningService = $c->get(PlanningService::class);
        $lineRepository = $c->get(PlanningLineRepository::class);
        $membershipService = $c->get(PlanningTeamMembershipService::class);
        $ruleSetService = $c->get(PlanningRuleSetService::class);
        $dutyMaterialization = $c->get(DutyMaterializationService::class);
        $snapshotService = $c->get(PlanningSnapshotService::class);
        $matrixBuilder = $c->get(EligibilityMatrixBuilder::class);
        $contextBuilder = $c->get(FairnessContextBuilder::class);
        $problemBuilder = $c->get(OptimizationProblemBuilder::class);
        $solver = $c->get(OrToolsPlanningSolver::class);

        $creator = $this->createUser($em);
        $planning = $this->createPlanning($planningService, $creator, 'Seniors');
        $line = $lineRepository->findByPlanning($planning)[0];
        $team = $line->getPlanningTeam();
        $planningPeriod = $line->getPlanningPeriod();
        $this->activateRuleSet($ruleSetService, $team);

        $userA = $this->createUser($em);
        $userB = $this->createUser($em);
        $this->addMember($membershipService, $team, $userA, TeamMemberRole::MEMBER, '2027-01-01', 1.0);
        $this->addMember($membershipService, $team, $userB, TeamMemberRole::MEMBER, '2027-01-01', 1.0);
        $dutyType = $this->createDutyType($em, $team);
        $this->createDuty($dutyMaterialization, $planningPeriod, $dutyType, '2027-02-01 08:00', '2027-02-01 20:00');

        $problem = $this->buildOptimizationProblem($em, $snapshotService, $matrixBuilder, $contextBuilder, $problemBuilder, $planningPeriod);

        return [$solver, $problem];
    }

    /**
     * @return array{0: OrToolsPlanningSolver, 1: OptimizationProblem, 2: array<string,string>}
     */
    private function scenarioTwoDutiesTwoCandidates(): array
    {
        self::bootKernel();
        $c = self::getContainer();
        $em = $c->get(EntityManagerInterface::class);
        $planningService = $c->get(PlanningService::class);
        $lineRepository = $c->get(PlanningLineRepository::class);
        $membershipService = $c->get(PlanningTeamMembershipService::class);
        $ruleSetService = $c->get(PlanningRuleSetService::class);
        $dutyMaterialization = $c->get(DutyMaterializationService::class);
        $snapshotService = $c->get(PlanningSnapshotService::class);
        $matrixBuilder = $c->get(EligibilityMatrixBuilder::class);
        $contextBuilder = $c->get(FairnessContextBuilder::class);
        $problemBuilder = $c->get(OptimizationProblemBuilder::class);
        $solver = $c->get(OrToolsPlanningSolver::class);

        $creator = $this->createUser($em);
        $planning = $this->createPlanning($planningService, $creator, 'Seniors');
        $line = $lineRepository->findByPlanning($planning)[0];
        $team = $line->getPlanningTeam();
        $planningPeriod = $line->getPlanningPeriod();
        $this->activateRuleSet($ruleSetService, $team);

        $userA = $this->createUser($em);
        $userB = $this->createUser($em);
        $memberA = $this->addMember($membershipService, $team, $userA, TeamMemberRole::MEMBER, '2027-01-01', 1.0);
        $memberB = $this->addMember($membershipService, $team, $userB, TeamMemberRole::MEMBER, '2027-01-01', 1.0);
        $dutyType = $this->createDutyType($em, $team);
        $duty1 = $this->createDuty($dutyMaterialization, $planningPeriod, $dutyType, '2027-02-01 08:00', '2027-02-01 20:00');
        $duty2 = $this->createDuty($dutyMaterialization, $planningPeriod, $dutyType, '2027-02-02 08:00', '2027-02-02 20:00');

        $problem = $this->buildOptimizationProblem($em, $snapshotService, $matrixBuilder, $contextBuilder, $problemBuilder, $planningPeriod);

        return [$solver, $problem, [
            'duty1' => (string) $duty1->getStableId(),
            'duty2' => (string) $duty2->getStableId(),
            'member1' => (string) $memberA->getStableId(),
            'member2' => (string) $memberB->getStableId(),
        ]];
    }

    /**
     * @return array{0: OrToolsPlanningSolver, 1: OptimizationProblem}
     */
    private function scenarioRequiredDutyWithNoCandidate(): array
    {
        self::bootKernel();
        $c = self::getContainer();
        $em = $c->get(EntityManagerInterface::class);
        $planningService = $c->get(PlanningService::class);
        $lineRepository = $c->get(PlanningLineRepository::class);
        $ruleSetService = $c->get(PlanningRuleSetService::class);
        $dutyMaterialization = $c->get(DutyMaterializationService::class);
        $snapshotService = $c->get(PlanningSnapshotService::class);
        $matrixBuilder = $c->get(EligibilityMatrixBuilder::class);
        $contextBuilder = $c->get(FairnessContextBuilder::class);
        $problemBuilder = $c->get(OptimizationProblemBuilder::class);
        $solver = $c->get(OrToolsPlanningSolver::class);

        $creator = $this->createUser($em);
        $planning = $this->createPlanning($planningService, $creator, 'Seniors');
        $line = $lineRepository->findByPlanning($planning)[0];
        $team = $line->getPlanningTeam();
        $planningPeriod = $line->getPlanningPeriod();
        $this->activateRuleSet($ruleSetService, $team);

        // No members at all -> zero eligible candidates for a REQUIRED duty.
        $dutyType = $this->createDutyType($em, $team);
        $this->createDuty($dutyMaterialization, $planningPeriod, $dutyType, '2027-02-01 08:00', '2027-02-01 20:00');

        $problem = $this->buildOptimizationProblem($em, $snapshotService, $matrixBuilder, $contextBuilder, $problemBuilder, $planningPeriod);

        return [$solver, $problem];
    }

    /**
     * Four REQUIRED duties, two members with identical membership start
     * (equal structural exposure) -> equal fairnessTargets on
     * TOTAL_DUTIES, the balanced 2/2 split minimizes maxDeviation.
     *
     * @return array{0: OrToolsPlanningSolver, 1: OptimizationProblem}
     */
    private function scenarioFourDutiesTwoEquallyExposedCandidates(): array
    {
        self::bootKernel();
        $c = self::getContainer();
        $em = $c->get(EntityManagerInterface::class);
        $planningService = $c->get(PlanningService::class);
        $lineRepository = $c->get(PlanningLineRepository::class);
        $membershipService = $c->get(PlanningTeamMembershipService::class);
        $ruleSetService = $c->get(PlanningRuleSetService::class);
        $dutyMaterialization = $c->get(DutyMaterializationService::class);
        $snapshotService = $c->get(PlanningSnapshotService::class);
        $matrixBuilder = $c->get(EligibilityMatrixBuilder::class);
        $contextBuilder = $c->get(FairnessContextBuilder::class);
        $problemBuilder = $c->get(OptimizationProblemBuilder::class);
        $solver = $c->get(OrToolsPlanningSolver::class);

        $creator = $this->createUser($em);
        $planning = $this->createPlanning($planningService, $creator, 'Seniors');
        $line = $lineRepository->findByPlanning($planning)[0];
        $team = $line->getPlanningTeam();
        $planningPeriod = $line->getPlanningPeriod();
        $this->activateRuleSet($ruleSetService, $team);

        $userA = $this->createUser($em);
        $userB = $this->createUser($em);
        $this->addMember($membershipService, $team, $userA, TeamMemberRole::MEMBER, '2027-01-01', 1.0);
        $this->addMember($membershipService, $team, $userB, TeamMemberRole::MEMBER, '2027-01-01', 1.0);

        $dutyType = $this->createDutyType($em, $team);
        $this->createDuty($dutyMaterialization, $planningPeriod, $dutyType, '2027-02-01 08:00', '2027-02-01 20:00');
        $this->createDuty($dutyMaterialization, $planningPeriod, $dutyType, '2027-02-02 08:00', '2027-02-02 20:00');
        $this->createDuty($dutyMaterialization, $planningPeriod, $dutyType, '2027-02-03 08:00', '2027-02-03 20:00');
        $this->createDuty($dutyMaterialization, $planningPeriod, $dutyType, '2027-02-04 08:00', '2027-02-04 20:00');

        $problem = $this->buildOptimizationProblem($em, $snapshotService, $matrixBuilder, $contextBuilder, $problemBuilder, $planningPeriod);

        return [$solver, $problem];
    }

    private function emptyProblem(): OptimizationProblem
    {
        return new OptimizationProblem(
            OptimizationMode::GENERATE,
            [],
            [],
            FairnessDimensionValues::empty(),
            new EligibilityMatrix([], [], []),
            [],
            [],
            [],
            new CoveragePolicy(true, []),
            [],
            [],
        );
    }

    private function buildSolverWithFixtureScript(string $fixtureFile): OrToolsPlanningSolver
    {
        self::bootKernel();
        $c = self::getContainer();

        return new OrToolsPlanningSolver(
            $c->get(CpSatPayloadBuilder::class),
            $c->get(UnsatDiagnosticsBuilder::class),
            '/opt/ortools-venv/bin/python3',
            __DIR__.'/fixtures/'.$fixtureFile,
        );
    }

    /**
     * @param list<DutyAssignmentEdge> $assignments
     *
     * @return list<string>
     */
    private function normalizeAssignments(array $assignments): array
    {
        $normalized = array_map(
            static fn (DutyAssignmentEdge $e): string => $e->dutyUnitStableKey.'::'.$e->sourceTeamMemberStableId,
            $assignments,
        );
        sort($normalized);

        return $normalized;
    }
}
