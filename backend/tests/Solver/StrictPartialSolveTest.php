<?php

declare(strict_types=1);

namespace App\Tests\Solver;

use App\Eligibility\EligibilityMatrix;
use App\Entity\Duty;
use App\Entity\DutyCriticality;
use App\Entity\DutyDemandType;
use App\Entity\TeamMemberRole;
use App\Fairness\CoveragePolicy;
use App\Fairness\CoverageStatus;
use App\Fairness\FairnessDimensionValues;
use App\Fairness\ObjectivePhaseId;
use App\Fairness\OptimizationMode;
use App\Fairness\OptimizationProblem;
use App\Fairness\SolverStatus;
use App\Fairness\UnsatReport;
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

final class StrictPartialSolveTest extends KernelTestCase
{
    use PlanningDomainTestHelpers;
    use PlanningGenerationTestHelpers;
    use PlanningTestHelpers;
    use SolverTestHelpers;

    // --- §23 STRICT -> PARTIAL routing -----------------------------------

    public function testStrictFeasibleSkipsPartialAndReportsComplete(): void
    {
        $solver = $this->buildSolverWithFixtureScript('echo_strict_feasible.py');

        $result = $solver->solve($this->minimalProblem());

        self::assertSame(SolverStatus::FEASIBLE, $result->strictSolverStatus);
        self::assertNull($result->partialSolverStatus, 'FEASIBLE is a conclusive STRICT result — PARTIAL must never be triggered');
        self::assertSame(CoverageStatus::COMPLETE, $result->coverageStatus);
    }

    public function testStrictOptimalNeverTriggersPartial(): void
    {
        [$solver, $problem] = $this->scenarioOneDutyOneCandidate();

        $result = $solver->solve($problem);

        self::assertSame(SolverStatus::OPTIMAL, $result->strictSolverStatus);
        self::assertNull($result->partialSolverStatus);
    }

    public function testStrictUnknownNeverTriggersPartial(): void
    {
        $solver = $this->buildSolverWithFixtureScript('echo_unknown.py');

        $result = $solver->solve($this->minimalProblem());

        self::assertSame(SolverStatus::UNKNOWN, $result->strictSolverStatus);
        self::assertNull($result->partialSolverStatus, 'UNKNOWN must never fall back to PARTIAL (docs/allocation-algorithm.md §10 step 5)');
    }

    public function testStrictErrorNeverTriggersPartial(): void
    {
        $solver = $this->buildSolverWithFixtureScript('echo_malformed.py');

        $result = $solver->solve($this->minimalProblem());

        self::assertSame(SolverStatus::ERROR, $result->strictSolverStatus);
        self::assertNull($result->partialSolverStatus);
    }

    public function testStrictUnsatisfiableTriggersPartial(): void
    {
        [$solver, $problem] = $this->scenarioOneRequiredDutyNoCandidate();

        $result = $solver->solve($problem);

        self::assertSame(SolverStatus::UNSATISFIABLE, $result->strictSolverStatus);
        self::assertNotNull($result->partialSolverStatus, 'a proven STRICT UNSAT must trigger PARTIAL');
    }

    public function testPartialWithOneUnassignedDutyReportsIncomplete(): void
    {
        [$solver, $problem] = $this->scenarioOneRequiredDutyNoCandidate();

        $result = $solver->solve($problem);

        self::assertSame(CoverageStatus::INCOMPLETE, $result->coverageStatus);
        self::assertCount(1, $result->unassignedDuties);
        self::assertInstanceOf(UnsatReport::class, $result->diagnostics);
    }

    public function testPartialWithMultipleUnassignedDutiesReportsAllOfThem(): void
    {
        [$solver, $problem, $keys] = $this->scenarioTwoRequiredDutiesNoCandidatesPlusOneCoverable();

        $result = $solver->solve($problem);

        self::assertSame(CoverageStatus::INCOMPLETE, $result->coverageStatus);
        self::assertCount(2, $result->unassignedDuties);
        $unassignedKeys = array_map(static fn ($d) => $d->dutyUnitStableKey, $result->unassignedDuties);
        sort($unassignedKeys);
        $expected = [$keys['noCandidate1'], $keys['noCandidate2']];
        sort($expected);
        self::assertSame($expected, $unassignedKeys);
        self::assertNotContains($keys['coverable'], $unassignedKeys);
    }

    public function testPartialRareCaseFullCoverageDespiteStrictUnsat(): void
    {
        $solver = $this->buildSolverWithFixtureScript('echo_partial_complete.py');

        $result = $solver->solve($this->minimalProblem());

        self::assertSame(SolverStatus::UNSATISFIABLE, $result->strictSolverStatus);
        self::assertSame(SolverStatus::OPTIMAL, $result->partialSolverStatus);
        self::assertSame(CoverageStatus::COMPLETE, $result->coverageStatus, 'coverageStatus must never be forced INCOMPLETE just because STRICT was UNSAT');
        self::assertNull($result->diagnostics, 'nothing remains unassigned — nothing left to diagnose');
    }

    public function testPartialItselfUnsatisfiableRoutesToExistingDataConflict(): void
    {
        $solver = $this->buildSolverWithFixtureScript('echo_partial_unsat.py');

        $result = $solver->solve($this->minimalProblem());

        self::assertSame(SolverStatus::UNSATISFIABLE, $result->strictSolverStatus);
        self::assertSame(SolverStatus::UNSATISFIABLE, $result->partialSolverStatus);
        self::assertInstanceOf(UnsatReport::class, $result->diagnostics);
        self::assertNotNull($result->diagnostics->existingDataConflict);
    }

    public function testPartialUnknownNeverBecomesProvenIncomplete(): void
    {
        $solver = $this->buildSolverWithFixtureScript('echo_partial_unknown.py');

        $result = $solver->solve($this->minimalProblem());

        self::assertSame(SolverStatus::UNSATISFIABLE, $result->strictSolverStatus);
        self::assertSame(SolverStatus::UNKNOWN, $result->partialSolverStatus);
        self::assertNotSame(SolverStatus::UNSATISFIABLE, $result->partialSolverStatus);
        self::assertNull($result->diagnostics, 'cannot diagnose an inconclusive PARTIAL solve');
    }

    // --- §24 CRITICAL priority --------------------------------------------

    public function testTwoCriticalDutiesBothUncoverableAreBothReportedUnassignedAndCritical(): void
    {
        [$solver, $problem] = $this->scenarioTwoCriticalDutiesNoCandidate();

        $result = $solver->solve($problem);

        self::assertCount(2, $result->unassignedDuties);
        foreach ($result->unassignedDuties as $duty) {
            self::assertTrue($duty->critical);
        }
        self::assertSame(2.0, $result->objectiveValues[ObjectivePhaseId::PARTIAL_COVERAGE_CRITICAL->value]);
        self::assertSame(2.0, $result->objectiveValues[ObjectivePhaseId::PARTIAL_COVERAGE_TOTAL->value]);
    }

    /**
     * docs/planning-solver.md: with today's constraint set (no MAX_DUTIES,
     * no CONFLICT/time-exclusivity — docs/eligibility.md §3), a candidate
     * eligible for two different DutyUnits can always cover both
     * simultaneously — there is no shared capacity anywhere in the model.
     * A CRITICAL and a STANDARD duty therefore never genuinely compete:
     * whenever each has its own eligible candidate, both get covered,
     * with nothing sacrificed. This is the honest, real behavior — never
     * a fabricated trade-off.
     */
    public function testCriticalAndStandardEachWithTheirOwnCandidateAreBothCoveredNothingSacrificed(): void
    {
        [$solver, $problem] = $this->scenarioCriticalAndStandardEachWithOwnCandidateButNeitherFullyStrictSatisfiable();

        $result = $solver->solve($problem);

        self::assertSame(CoverageStatus::COMPLETE, $result->coverageStatus);
        self::assertCount(0, $result->unassignedDuties);
    }

    public function testFairnessNeverIncreasesUnassignedCount(): void
    {
        [$solver, $problem, $keys] = $this->scenarioOneUncoverablePlusTwoCoverableWithFairnessData();

        $result = $solver->solve($problem);

        self::assertCount(1, $result->unassignedDuties);
        self::assertSame($keys['noCandidate'], $result->unassignedDuties[0]->dutyUnitStableKey);
        self::assertTrue($result->optimality[ObjectivePhaseId::MAX_DEVIATION_SECONDARY->value] ?? false);
    }

    // --- §25 fairness in PARTIAL --------------------------------------------

    public function testRequiredDemandNeverChangesAfterAPartialSolve(): void
    {
        [$solver, $problem] = $this->scenarioOneRequiredDutyNoCandidate();
        $before = $problem->getRequiredDemand();

        $solver->solve($problem);

        self::assertSame($before, $problem->getRequiredDemand());
    }

    public function testProblemIsNeverMutatedWhenPartialIsTriggered(): void
    {
        [$solver, $problem] = $this->scenarioOneRequiredDutyNoCandidate();
        $requiredBefore = $problem->getRequiredDutyUnits();
        $phasesBefore = $problem->getObjectivePhases();

        $solver->solve($problem);

        self::assertSame($requiredBefore, $problem->getRequiredDutyUnits());
        self::assertSame($phasesBefore, $problem->getObjectivePhases());
    }

    public function testPrimaryStaysNeutralInPartialToo(): void
    {
        [$solver, $problem] = $this->scenarioOneUncoverablePlusTwoCoverableWithFairnessData();

        $result = $solver->solve($problem);

        self::assertSame(0.0, $result->objectiveValues[ObjectivePhaseId::MAX_DEVIATION_PRIMARY->value]);
        self::assertSame(0.0, $result->objectiveValues[ObjectivePhaseId::SUM_DEVIATION_PRIMARY->value]);
    }

    public function testSameProblemSolvedTwiceWithPartialProducesTheSameResult(): void
    {
        [$solver, $problem] = $this->scenarioTwoRequiredDutiesNoCandidatesPlusOneCoverable();

        $first = $solver->solve($problem);
        $second = $solver->solve($problem);

        self::assertSame($first->strictSolverStatus, $second->strictSolverStatus);
        self::assertSame($first->partialSolverStatus, $second->partialSolverStatus);
        self::assertSame($first->coverageStatus, $second->coverageStatus);
        $firstKeys = array_map(static fn ($d) => $d->dutyUnitStableKey, $first->unassignedDuties);
        $secondKeys = array_map(static fn ($d) => $d->dutyUnitStableKey, $second->unassignedDuties);
        sort($firstKeys);
        sort($secondKeys);
        self::assertSame($firstKeys, $secondKeys);
    }

    // --- payload-level: phase order & OPTIONAL exclusion --------------------

    public function testPartialPayloadPrependsCoveragePhasesBeforeGeneratePhases(): void
    {
        self::bootKernel();
        $c = self::getContainer();
        $payloadBuilder = $c->get(CpSatPayloadBuilder::class);
        [, $problem] = $this->scenarioOneRequiredDutyNoCandidate();

        $payload = $payloadBuilder->buildPartialSolvePayload($problem);

        $ids = array_column($payload['phases'], 'id');

        self::assertSame(ObjectivePhaseId::PARTIAL_COVERAGE_CRITICAL->value, $ids[0]);
        self::assertSame(ObjectivePhaseId::PARTIAL_COVERAGE_TOTAL->value, $ids[1]);
        self::assertSame(ObjectivePhaseId::MAX_DEVIATION_PRIMARY->value, $ids[2]);
        self::assertSame(ObjectivePhaseId::DETERMINISTIC_TIE_BREAK->value, $ids[\count($ids) - 1]);
        self::assertCount(10, $ids, '2 coverage phases + the 8 GENERATE phases');
    }

    public function testOptionalDutyUnitsNeverAppearInCoveragePhaseKeys(): void
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
        $payloadBuilder = $c->get(CpSatPayloadBuilder::class);

        $creator = $this->createUser($em);
        $planning = $this->createPlanning($planningService, $creator, 'Seniors');
        $line = $lineRepository->findByPlanning($planning)[0];
        $team = $line->getPlanningTeam();
        $planningPeriod = $line->getPlanningPeriod();
        $this->activateRuleSet($ruleSetService, $team);

        $dutyType = $this->createDutyType($em, $team);
        $requiredDuty = $this->createDuty($dutyMaterialization, $planningPeriod, $dutyType, '2027-02-01 08:00', '2027-02-01 20:00');
        $optionalDuty = $dutyMaterialization->createStandaloneDuty($planningPeriod, $dutyType, new \DateTimeImmutable('2027-02-02 08:00'), new \DateTimeImmutable('2027-02-02 20:00'), DutyDemandType::OPTIONAL);

        $problem = $this->buildOptimizationProblem($em, $snapshotService, $matrixBuilder, $contextBuilder, $problemBuilder, $planningPeriod);
        $payload = $payloadBuilder->buildPartialSolvePayload($problem);

        $coveragePhase = $payload['phases'][1];
        self::assertSame(ObjectivePhaseId::PARTIAL_COVERAGE_TOTAL->value, $coveragePhase['id']);
        self::assertContains((string) $requiredDuty->getStableId(), $coveragePhase['dutyUnitKeys']);
        self::assertNotContains((string) $optionalDuty->getStableId(), $coveragePhase['dutyUnitKeys']);
    }

    public function testSecondaryFairnessStillOptimizesInPartialWithMixedCoverage(): void
    {
        [$solver, $problem] = $this->scenarioOneUncoverablePlusTwoCoverableWithFairnessData();

        $result = $solver->solve($problem);

        self::assertArrayHasKey(ObjectivePhaseId::MAX_DEVIATION_SECONDARY->value, $result->objectiveValues);
        self::assertArrayHasKey(ObjectivePhaseId::SUM_DEVIATION_SECONDARY->value, $result->objectiveValues);
    }

    // --- scenario builders ---------------------------------------------------

    private function minimalProblem(): OptimizationProblem
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
     * @return array{0: OrToolsPlanningSolver, 1: OptimizationProblem}
     */
    private function scenarioOneDutyOneCandidate(): array
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
        $this->addMember($membershipService, $team, $user, TeamMemberRole::MEMBER, '2027-01-01', 1.0);
        $dutyType = $this->createDutyType($em, $team);
        $this->createDuty($dutyMaterialization, $planningPeriod, $dutyType, '2027-02-01 08:00', '2027-02-01 20:00');

        $problem = $this->buildOptimizationProblem($em, $snapshotService, $matrixBuilder, $contextBuilder, $problemBuilder, $planningPeriod);

        return [$solver, $problem];
    }

    /**
     * A single REQUIRED duty, no team member at all -> zero eligible
     * candidates -> STRICT UNSATISFIABLE.
     *
     * @return array{0: OrToolsPlanningSolver, 1: OptimizationProblem}
     */
    private function scenarioOneRequiredDutyNoCandidate(): array
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

        $dutyType = $this->createDutyType($em, $team);
        $this->createDuty($dutyMaterialization, $planningPeriod, $dutyType, '2027-02-01 08:00', '2027-02-01 20:00');

        $problem = $this->buildOptimizationProblem($em, $snapshotService, $matrixBuilder, $contextBuilder, $problemBuilder, $planningPeriod);

        return [$solver, $problem];
    }

    /**
     * Two REQUIRED duties with zero candidates, plus one REQUIRED duty a
     * real member can cover.
     *
     * @return array{0: OrToolsPlanningSolver, 1: OptimizationProblem, 2: array<string,string>}
     */
    private function scenarioTwoRequiredDutiesNoCandidatesPlusOneCoverable(): array
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

        // Member joins after the two "no candidate" duties but before the
        // coverable one -> MEMBERSHIP_OUT_OF_RANGE (HARD) for the first two.
        $user = $this->createUser($em);
        $this->addMember($membershipService, $team, $user, TeamMemberRole::MEMBER, '2027-02-10', 1.0);

        $dutyType = $this->createDutyType($em, $team);
        $noCandidate1 = $this->createDuty($dutyMaterialization, $planningPeriod, $dutyType, '2027-02-01 08:00', '2027-02-01 20:00');
        $noCandidate2 = $this->createDuty($dutyMaterialization, $planningPeriod, $dutyType, '2027-02-02 08:00', '2027-02-02 20:00');
        $coverable = $this->createDuty($dutyMaterialization, $planningPeriod, $dutyType, '2027-02-15 08:00', '2027-02-15 20:00');

        $problem = $this->buildOptimizationProblem($em, $snapshotService, $matrixBuilder, $contextBuilder, $problemBuilder, $planningPeriod);

        return [$solver, $problem, [
            'noCandidate1' => (string) $noCandidate1->getStableId(),
            'noCandidate2' => (string) $noCandidate2->getStableId(),
            'coverable' => (string) $coverable->getStableId(),
        ]];
    }

    /**
     * @return array{0: OrToolsPlanningSolver, 1: OptimizationProblem}
     */
    private function scenarioTwoCriticalDutiesNoCandidate(): array
    {
        self::bootKernel();
        $c = self::getContainer();
        $em = $c->get(EntityManagerInterface::class);
        $planningService = $c->get(PlanningService::class);
        $lineRepository = $c->get(PlanningLineRepository::class);
        $ruleSetService = $c->get(PlanningRuleSetService::class);
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

        $dutyType = $this->createDutyType($em, $team);
        $planningPeriodEntity = $planningPeriod;
        $tz = 'Europe/Brussels';
        $duty1 = new Duty($planningPeriodEntity, $dutyType, new \DateTimeImmutable('2027-02-01 08:00', new \DateTimeZone($tz)), new \DateTimeImmutable('2027-02-01 20:00', new \DateTimeZone($tz)), $tz, DutyDemandType::REQUIRED, DutyCriticality::CRITICAL);
        $duty2 = new Duty($planningPeriodEntity, $dutyType, new \DateTimeImmutable('2027-02-02 08:00', new \DateTimeZone($tz)), new \DateTimeImmutable('2027-02-02 20:00', new \DateTimeZone($tz)), $tz, DutyDemandType::REQUIRED, DutyCriticality::CRITICAL);
        $em->persist($duty1);
        $em->persist($duty2);
        $em->flush();

        $problem = $this->buildOptimizationProblem($em, $snapshotService, $matrixBuilder, $contextBuilder, $problemBuilder, $planningPeriod);

        return [$solver, $problem];
    }

    /**
     * @return array{0: OrToolsPlanningSolver, 1: OptimizationProblem}
     */
    private function scenarioCriticalAndStandardEachWithOwnCandidateButNeitherFullyStrictSatisfiable(): array
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
        $this->addMember($membershipService, $team, $user, TeamMemberRole::MEMBER, '2027-01-01', 1.0);

        $dutyType = $this->createDutyType($em, $team);
        $tz = 'Europe/Brussels';
        // STRICT already succeeds here (both units have >=1 candidate) —
        // this scenario exists to document/verify the "no contention"
        // finding, not to force UNSAT.
        $critical = new Duty($planningPeriod, $dutyType, new \DateTimeImmutable('2027-02-01 08:00', new \DateTimeZone($tz)), new \DateTimeImmutable('2027-02-01 20:00', new \DateTimeZone($tz)), $tz, DutyDemandType::REQUIRED, DutyCriticality::CRITICAL);
        $standard = new Duty($planningPeriod, $dutyType, new \DateTimeImmutable('2027-02-02 08:00', new \DateTimeZone($tz)), new \DateTimeImmutable('2027-02-02 20:00', new \DateTimeZone($tz)), $tz, DutyDemandType::REQUIRED, DutyCriticality::STANDARD);
        $em->persist($critical);
        $em->persist($standard);
        $em->flush();

        $problem = $this->buildOptimizationProblem($em, $snapshotService, $matrixBuilder, $contextBuilder, $problemBuilder, $planningPeriod);

        return [$solver, $problem];
    }

    /**
     * One uncoverable REQUIRED duty (no candidate) plus two coverable ones
     * on different weekdays with two equally-exposed candidates, so
     * SECONDARY fairness (TOTAL_DUTIES/FRIDAY/SATURDAY/SUNDAY) has real
     * data to optimize alongside the coverage shortfall.
     *
     * @return array{0: OrToolsPlanningSolver, 1: OptimizationProblem, 2: array<string,string>}
     */
    private function scenarioOneUncoverablePlusTwoCoverableWithFairnessData(): array
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
        // Both join after the uncoverable duty's date but before the two
        // coverable ones -> only the uncoverable duty has zero candidates.
        $this->addMember($membershipService, $team, $userA, TeamMemberRole::MEMBER, '2027-02-05', 1.0);
        $this->addMember($membershipService, $team, $userB, TeamMemberRole::MEMBER, '2027-02-05', 1.0);

        $dutyType = $this->createDutyType($em, $team);
        $noCandidate = $this->createDuty($dutyMaterialization, $planningPeriod, $dutyType, '2027-02-01 08:00', '2027-02-01 20:00');
        $this->createDuty($dutyMaterialization, $planningPeriod, $dutyType, '2027-02-06 08:00', '2027-02-06 20:00');
        $this->createDuty($dutyMaterialization, $planningPeriod, $dutyType, '2027-02-07 08:00', '2027-02-07 20:00');

        $problem = $this->buildOptimizationProblem($em, $snapshotService, $matrixBuilder, $contextBuilder, $problemBuilder, $planningPeriod);

        return [$solver, $problem, ['noCandidate' => (string) $noCandidate->getStableId()]];
    }
}
