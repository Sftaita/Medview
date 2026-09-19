<?php

declare(strict_types=1);

namespace App\Tests\Solver;

use App\Eligibility\EligibilityMatrix;
use App\Entity\TeamMemberRole;
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

    public function testNeutralPhasesNeverDegradeAnythingHoldingNoRealDataInThisLot(): void
    {
        [$solver, $problem] = $this->scenarioFourDutiesTwoEquallyExposedCandidates();

        $result = $solver->solve($problem);

        foreach ([
            ObjectivePhaseId::NAMED_HOLIDAY_REPETITION_PENALTY,
            ObjectivePhaseId::SPACING_SCORE,
            ObjectivePhaseId::PREFERENCE_SATISFACTION,
            ObjectivePhaseId::DETERMINISTIC_TIE_BREAK,
        ] as $id) {
            self::assertSame(0.0, $result->objectiveValues[$id->value], sprintf('%s must be neutral (no real data backs it in this lot)', $id->value));
            self::assertTrue($result->optimality[$id->value], sprintf('%s must be trivially "optimal" as a neutral phase', $id->value));
        }
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
