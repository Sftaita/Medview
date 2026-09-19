<?php

declare(strict_types=1);

namespace App\Tests\Solver;

use App\Entity\Duty;
use App\Entity\DutyCriticality;
use App\Entity\DutyDemandType;
use App\Entity\RestPolicyOptions;
use App\Entity\TeamMemberRole;
use App\Fairness\OptimizationProblem;
use App\Fairness\SolverStatus;
use App\Fairness\StructuralDiagnosticCode;
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
use App\Solver\OrToolsPlanningSolver;
use App\Tests\PlanningDomainTestHelpers;
use App\Tests\PlanningGenerationTestHelpers;
use App\Tests\PlanningTestHelpers;
use App\Tests\SolverTestHelpers;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Lot 6D — real CP-SAT scenarios where the solver must actually arbitrate
 * between competing DutyUnits, unlike Lot 6B/6C (docs/decisions.md D099).
 */
final class GlobalConstraintsSolveTest extends KernelTestCase
{
    use PlanningDomainTestHelpers;
    use PlanningGenerationTestHelpers;
    use PlanningTestHelpers;
    use SolverTestHelpers;

    public function testTwoOverlappingRequiredDutiesSameCandidateMakesStrictUnsat(): void
    {
        [$solver, $problem] = $this->scenarioTwoOverlappingRequiredDutiesOneCandidate();

        $result = $solver->solve($problem);

        self::assertSame(SolverStatus::UNSATISFIABLE, $result->strictSolverStatus);
    }

    public function testConflictDrivenPartialLeavesExactlyOneDutyUnassigned(): void
    {
        [$solver, $problem] = $this->scenarioTwoOverlappingRequiredDutiesOneCandidate();

        $result = $solver->solve($problem);

        self::assertNotNull($result->partialSolverStatus);
        self::assertCount(1, $result->unassignedDuties);
        self::assertCount(1, $result->assignments);
    }

    public function testTwoCandidatesResolveAnOverlapBySplittingTheDuties(): void
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
        $this->createDuty($dutyMaterialization, $planningPeriod, $dutyType, '2027-02-01 18:00', '2027-02-02 06:00');

        $problem = $this->buildOptimizationProblem($em, $snapshotService, $matrixBuilder, $contextBuilder, $problemBuilder, $planningPeriod);
        $result = $solver->solve($problem);

        self::assertSame(SolverStatus::OPTIMAL, $result->strictSolverStatus);
        self::assertCount(2, $result->assignments);
        $assignedCandidates = array_map(static fn ($a) => $a->sourceTeamMemberStableId, $result->assignments);
        self::assertCount(2, array_unique($assignedCandidates), 'the two overlapping duties must go to two different candidates');
    }

    public function testCriticalVsStandardRealConflictKeepsCriticalCoveredAndSacrificesStandard(): void
    {
        [$solver, $problem, $keys] = $this->scenarioCriticalStandardConflictOneCandidate();

        $result = $solver->solve($problem);

        self::assertNotNull($result->partialSolverStatus);
        self::assertCount(1, $result->assignments);
        self::assertSame($keys['critical'], $result->assignments[0]->dutyUnitStableKey, 'the CRITICAL duty must be the one actually covered');
        self::assertCount(1, $result->unassignedDuties);
        self::assertSame($keys['standard'], $result->unassignedDuties[0]->dutyUnitStableKey);
        self::assertFalse($result->unassignedDuties[0]->critical, 'the unassigned duty must be the STANDARD one, never critical');
    }

    public function testTwoCriticalIncompatibleDutiesMinimizeCriticalUnassignedToOne(): void
    {
        self::bootKernel();
        $c = self::getContainer();
        $em = $c->get(EntityManagerInterface::class);
        $planningService = $c->get(PlanningService::class);
        $lineRepository = $c->get(PlanningLineRepository::class);
        $membershipService = $c->get(PlanningTeamMembershipService::class);
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

        $user = $this->createUser($em);
        $this->addMember($membershipService, $team, $user, TeamMemberRole::MEMBER, '2027-01-01', 1.0);

        $dutyType = $this->createDutyType($em, $team);
        $tz = 'Europe/Brussels';
        $duty1 = new Duty($planningPeriod, $dutyType, new \DateTimeImmutable('2027-02-01 08:00', new \DateTimeZone($tz)), new \DateTimeImmutable('2027-02-01 20:00', new \DateTimeZone($tz)), $tz, DutyDemandType::REQUIRED, DutyCriticality::CRITICAL);
        $duty2 = new Duty($planningPeriod, $dutyType, new \DateTimeImmutable('2027-02-01 18:00', new \DateTimeZone($tz)), new \DateTimeImmutable('2027-02-02 06:00', new \DateTimeZone($tz)), $tz, DutyDemandType::REQUIRED, DutyCriticality::CRITICAL);
        $em->persist($duty1);
        $em->persist($duty2);
        $em->flush();

        $problem = $this->buildOptimizationProblem($em, $snapshotService, $matrixBuilder, $contextBuilder, $problemBuilder, $planningPeriod);
        $result = $solver->solve($problem);

        self::assertCount(1, $result->unassignedDuties);
        self::assertTrue($result->unassignedDuties[0]->critical, 'one of the two CRITICAL duties is unavoidably unassigned — minimum possible (1), still flagged critical');
    }

    public function testTeamMinRestBlocksStrictSolveAndPartialLeavesOneUnassigned(): void
    {
        [$solver, $problem] = $this->scenarioTeamMinRestViolation();

        $result = $solver->solve($problem);

        self::assertSame(SolverStatus::UNSATISFIABLE, $result->strictSolverStatus);
        self::assertNotNull($result->partialSolverStatus);
        self::assertCount(1, $result->unassignedDuties);
    }

    public function testTeamMinRestRelaxationDiagnosticProposesRemovingItAndNeverAHardRule(): void
    {
        [$solver, $problem] = $this->scenarioTeamMinRestViolation();

        $result = $solver->solve($problem);

        self::assertInstanceOf(UnsatReport::class, $result->diagnostics);
        self::assertNotEmpty($result->diagnostics->diagnosticRelaxations, 'removing TEAM_MIN_REST restores full coverage in this scenario — must be proposed');
        foreach ($result->diagnostics->diagnosticRelaxations as $relaxation) {
            self::assertSame(\App\Eligibility\ExclusionReason::TEAM_MIN_REST, $relaxation->ruleCode);
            self::assertSame(\App\Eligibility\ConstraintTier::POLICY_HARD, $relaxation->tier);
            self::assertStringNotContainsString('est la cause', $relaxation->phrasing, 'phrasing must stay conditional, never a certainty claim');
        }
    }

    public function testLegalMinRestEnabledWithSufficientGapAllowsSameCandidateOnBoth(): void
    {
        [$solver, $problem] = $this->scenarioLegalMinRest(11, '2027-02-02 07:00');

        $result = $solver->solve($problem);

        self::assertSame(SolverStatus::OPTIMAL, $result->strictSolverStatus);
        self::assertCount(2, $result->assignments);
        $assignedCandidates = array_map(static fn ($a) => $a->sourceTeamMemberStableId, $result->assignments);
        self::assertCount(1, array_unique($assignedCandidates), 'the 11h gap satisfies the legal minimum, so the same candidate can take both');
    }

    public function testLegalMinRestBlocksStrictSolveWhenGapIsBelowTheLegalMinimum(): void
    {
        [$solver, $problem] = $this->scenarioLegalMinRest(11, '2027-02-02 06:00');

        $result = $solver->solve($problem);

        self::assertSame(SolverStatus::UNSATISFIABLE, $result->strictSolverStatus, 'LEGAL_MIN_REST is HARD once enabled — a sub-minimum gap must make STRICT infeasible');
    }

    public function testLegalMinRestPartialLeavesOneDutyUnassignedAndNeverViolatesLegal(): void
    {
        [$solver, $problem] = $this->scenarioLegalMinRest(11, '2027-02-02 06:00');

        $result = $solver->solve($problem);

        self::assertNotNull($result->partialSolverStatus);
        self::assertCount(1, $result->unassignedDuties);
        self::assertCount(1, $result->assignments, 'PARTIAL must never assign both conflicting duties to the same candidate — that would violate LEGAL_MIN_REST, which stays HARD even in PARTIAL');
    }

    /**
     * docs/decisions.md D105 §18 item 31 — the critical negative case: when
     * LEGAL_MIN_REST alone (HARD, never relaxable) is what makes STRICT
     * infeasible, `diagnosticRelaxations` must stay empty. Even if
     * TEAM_MIN_REST is also enabled with a laxer threshold, the analyzer
     * only ever records LEGAL_MIN_REST for a pair violating both (see
     * AssignmentConflictAnalyzerTest), so there is no POLICY_HARD conflict
     * to even attempt removing — no misleading "removing TEAM_MIN_REST
     * would help" relaxation may ever be produced.
     */
    public function testLegalMinRestAloneNeverProducesAMisleadingRelaxation(): void
    {
        [$solver, $problem] = $this->scenarioLegalAndTeamBothViolatedOnlyLegalRecorded();

        $result = $solver->solve($problem);

        self::assertSame(SolverStatus::UNSATISFIABLE, $result->strictSolverStatus);
        self::assertInstanceOf(UnsatReport::class, $result->diagnostics);
        self::assertSame([], $result->diagnostics->diagnosticRelaxations, 'no relaxation may ever be proposed when the only active conflict is HARD-tier LEGAL_MIN_REST');
    }

    public function testInsufficientEligibleCapacityDiagnosedWhenExactlyOneSharedCandidate(): void
    {
        [$solver, $problem] = $this->scenarioTwoOverlappingRequiredDutiesOneCandidate();

        $result = $solver->solve($problem);

        self::assertInstanceOf(UnsatReport::class, $result->diagnostics);
        $codes = array_map(static fn ($d) => $d->code, $result->diagnostics->structuralDiagnostics);
        self::assertContains(StructuralDiagnosticCode::INSUFFICIENT_ELIGIBLE_CAPACITY, $codes);
    }

    public function testUnavailabilityNeverProducesAGlobalConstraintOnlyAnAbsentEdge(): void
    {
        self::bootKernel();
        $c = self::getContainer();
        $em = $c->get(EntityManagerInterface::class);
        $planningService = $c->get(PlanningService::class);
        $lineRepository = $c->get(PlanningLineRepository::class);
        $membershipService = $c->get(PlanningTeamMembershipService::class);
        $ruleSetService = $c->get(PlanningRuleSetService::class);
        $dutyMaterialization = $c->get(DutyMaterializationService::class);
        $availabilityService = $c->get(\App\Service\UserAvailabilityService::class);
        $snapshotService = $c->get(PlanningSnapshotService::class);
        $matrixBuilder = $c->get(EligibilityMatrixBuilder::class);
        $contextBuilder = $c->get(FairnessContextBuilder::class);
        $problemBuilder = $c->get(OptimizationProblemBuilder::class);

        $creator = $this->createUser($em);
        $planning = $this->createPlanning($planningService, $creator, 'Seniors');
        $line = $lineRepository->findByPlanning($planning)[0];
        $team = $line->getPlanningTeam();
        $planningPeriod = $line->getPlanningPeriod();
        $this->activateRuleSet($ruleSetService, $team);

        $user = $this->createUser($em);
        $this->addMember($membershipService, $team, $user, TeamMemberRole::MEMBER, '2027-01-01', 1.0);
        $this->addAvailability($availabilityService, $user, \App\Entity\UserAvailabilityType::UNAVAILABLE, '2027-02-01 00:00', '2027-02-02 00:00');

        $dutyType = $this->createDutyType($em, $team);
        $this->createDuty($dutyMaterialization, $planningPeriod, $dutyType, '2027-02-01 08:00', '2027-02-01 20:00');

        $problem = $this->buildOptimizationProblem($em, $snapshotService, $matrixBuilder, $contextBuilder, $problemBuilder, $planningPeriod);

        self::assertSame([], $problem->getAssignmentConflicts(), 'UNAVAILABLE removes an edge (docs/eligibility.md) — it must never also produce an AssignmentConflict');
    }

    // --- scenario builders ---------------------------------------------------

    /**
     * Two overlapping REQUIRED duties, one candidate eligible for both ->
     * a real, honest conflict (docs/decisions.md D099 no longer applies).
     *
     * @return array{0: OrToolsPlanningSolver, 1: OptimizationProblem}
     */
    private function scenarioTwoOverlappingRequiredDutiesOneCandidate(): array
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
        $this->createDuty($dutyMaterialization, $planningPeriod, $dutyType, '2027-02-01 18:00', '2027-02-02 06:00');

        $problem = $this->buildOptimizationProblem($em, $snapshotService, $matrixBuilder, $contextBuilder, $problemBuilder, $planningPeriod);

        return [$solver, $problem];
    }

    /**
     * @return array{0: OrToolsPlanningSolver, 1: OptimizationProblem, 2: array<string,string>}
     */
    private function scenarioCriticalStandardConflictOneCandidate(): array
    {
        self::bootKernel();
        $c = self::getContainer();
        $em = $c->get(EntityManagerInterface::class);
        $planningService = $c->get(PlanningService::class);
        $lineRepository = $c->get(PlanningLineRepository::class);
        $membershipService = $c->get(PlanningTeamMembershipService::class);
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

        $user = $this->createUser($em);
        $this->addMember($membershipService, $team, $user, TeamMemberRole::MEMBER, '2027-01-01', 1.0);

        $dutyType = $this->createDutyType($em, $team);
        $tz = 'Europe/Brussels';
        $critical = new Duty($planningPeriod, $dutyType, new \DateTimeImmutable('2027-02-01 08:00', new \DateTimeZone($tz)), new \DateTimeImmutable('2027-02-01 20:00', new \DateTimeZone($tz)), $tz, DutyDemandType::REQUIRED, DutyCriticality::CRITICAL);
        $standard = new Duty($planningPeriod, $dutyType, new \DateTimeImmutable('2027-02-01 18:00', new \DateTimeZone($tz)), new \DateTimeImmutable('2027-02-02 06:00', new \DateTimeZone($tz)), $tz, DutyDemandType::REQUIRED, DutyCriticality::STANDARD);
        $em->persist($critical);
        $em->persist($standard);
        $em->flush();

        $problem = $this->buildOptimizationProblem($em, $snapshotService, $matrixBuilder, $contextBuilder, $problemBuilder, $planningPeriod);

        return [$solver, $problem, [
            'critical' => (string) $critical->getStableId(),
            'standard' => (string) $standard->getStableId(),
        ]];
    }

    /**
     * Two REQUIRED duties, one candidate, gap below the configured
     * teamMinRestHours -> STRICT UNSAT purely on TEAM_MIN_REST (POLICY_HARD).
     *
     * @return array{0: OrToolsPlanningSolver, 1: OptimizationProblem}
     */
    private function scenarioTeamMinRestViolation(): array
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
        // 10h gap: 2027-02-01 20:00 -> 2027-02-02 06:00, below the 11h minimum.
        $this->createDuty($dutyMaterialization, $planningPeriod, $dutyType, '2027-02-01 08:00', '2027-02-01 20:00');
        $this->createDuty($dutyMaterialization, $planningPeriod, $dutyType, '2027-02-02 06:00', '2027-02-02 18:00');

        $restPolicy = new RestPolicyOptions(false, null, true, 11);
        $problem = $this->buildOptimizationProblem($em, $snapshotService, $matrixBuilder, $contextBuilder, $problemBuilder, $planningPeriod, $restPolicy);

        return [$solver, $problem];
    }

    /**
     * Two REQUIRED duties, one candidate, LEGAL_MIN_REST enabled at
     * $legalHours; the second duty starts at $secondDutyStart, giving a gap
     * either above or below that minimum depending on the caller.
     *
     * @return array{0: OrToolsPlanningSolver, 1: OptimizationProblem}
     */
    private function scenarioLegalMinRest(int $legalHours, string $secondDutyStart): array
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
        $this->createDuty($dutyMaterialization, $planningPeriod, $dutyType, $secondDutyStart, '2027-02-02 18:00');

        $restPolicy = new RestPolicyOptions(true, $legalHours, false, null);
        $problem = $this->buildOptimizationProblem($em, $snapshotService, $matrixBuilder, $contextBuilder, $problemBuilder, $planningPeriod, $restPolicy);

        return [$solver, $problem];
    }

    /**
     * Both LEGAL_MIN_REST (11h) and TEAM_MIN_REST (14h) enabled; the actual
     * gap (5h) is below both, but AssignmentConflictAnalyzer only ever
     * records LEGAL_MIN_REST for a pair violating both (never duplicated as
     * TEAM_MIN_REST too) — so the only conflict feeding this problem is
     * HARD-tier, and no POLICY_HARD conflict exists to attempt relaxing.
     *
     * @return array{0: OrToolsPlanningSolver, 1: OptimizationProblem}
     */
    private function scenarioLegalAndTeamBothViolatedOnlyLegalRecorded(): array
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
        // 5h gap: 2027-02-01 20:00 -> 2027-02-02 01:00, below both 11h and 14h.
        $this->createDuty($dutyMaterialization, $planningPeriod, $dutyType, '2027-02-01 08:00', '2027-02-01 20:00');
        $this->createDuty($dutyMaterialization, $planningPeriod, $dutyType, '2027-02-02 01:00', '2027-02-02 13:00');

        $restPolicy = new RestPolicyOptions(true, 11, true, 14);
        $problem = $this->buildOptimizationProblem($em, $snapshotService, $matrixBuilder, $contextBuilder, $problemBuilder, $planningPeriod, $restPolicy);

        return [$solver, $problem];
    }
}
