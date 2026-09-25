<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Duty;
use App\Entity\DutyAssignmentSource;
use App\Entity\PlanningGeneration;
use App\Entity\PlanningGenerationStatus;
use App\Entity\PlanningPeriod;
use App\Entity\PlanningPeriodStatus;
use App\Entity\SolverParameterSet;
use App\Entity\TeamMemberRole;
use App\Exception\NoSolverParameterSetException;
use App\Exception\PlanningGenerationConcurrentSolveException;
use App\Fairness\CoverageStatus;
use App\Fairness\OptimizationResult;
use App\Fairness\SolverStatus;
use App\Repository\DutyAssignmentRepository;
use App\Repository\DutyRepository;
use App\Repository\PlanningGenerationRepository;
use App\Repository\PlanningLineRepository;
use App\Repository\PlanningSnapshotRepository;
use App\Repository\PlanningSnapshotRuleSetRepository;
use App\Repository\PlanningTeamMemberRepository;
use App\Repository\SolverParameterSetRepository;
use App\Service\DutyAssignmentService;
use App\Service\DutyMaterializationService;
use App\Service\EligibilityMatrixBuilder;
use App\Service\FairnessContextBuilder;
use App\Service\OptimizationProblemBuilder;
use App\Service\PlanningGenerationService;
use App\Service\PlanningRuleSetService;
use App\Service\PlanningService;
use App\Service\PlanningSnapshotService;
use App\Service\PlanningTeamMembershipService;
use App\Service\SeedMaterialBuilder;
use App\Service\SnapshotHasher;
use App\Service\UnsatReportPresenter;
use App\Tests\Fairness\FakePlanningSolver;
use App\Tests\PlanningDomainTestHelpers;
use App\Tests\PlanningGenerationTestHelpers;
use App\Tests\PlanningTestHelpers;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Lot 6E orchestration (docs/decisions.md D106) — the full
 * PlanningGeneration → OptimizationProblem → PlanningSolver →
 * DutyAssignment AUTO pipeline, atomicity, statuses, and concurrency.
 */
final class PlanningGenerationServiceTest extends KernelTestCase
{
    use PlanningDomainTestHelpers;
    use PlanningGenerationTestHelpers;
    use PlanningTestHelpers;

    // --- lifecycle / preconditions ------------------------------------

    public function testDraftGenerationCannotBeSolved(): void
    {
        [$service, $generation] = $this->scenarioTwoCandidatesTwoDuties(snapshot: false);

        $this->expectException(PlanningGenerationConcurrentSolveException::class);
        $service->generate($generation);
    }

    public function testNoSolverParameterSetIsARealPrecondition(): void
    {
        self::bootKernel();
        $c = self::getContainer();
        $em = $c->get(EntityManagerInterface::class);

        // Remove the migration-seeded SolverParameterSet for this one test
        // only — dama/doctrine-test-bundle rolls the whole test back
        // afterwards, so every other test still sees it.
        $em->createQuery('DELETE FROM App\Entity\SolverParameterSet')->execute();

        [$service, $generation] = $this->scenarioTwoCandidatesTwoDuties();

        $this->expectException(NoSolverParameterSetException::class);
        $service->generate($generation);
    }

    // --- persistence of a real, successful outcome ---------------------

    public function testStrictCompleteSolvePersistsAutoAssignments(): void
    {
        [$service, $generation, $em, $dutyAssignmentRepository] = $this->scenarioTwoCandidatesTwoDuties();

        $result = $service->generate($generation);

        self::assertSame(SolverStatus::OPTIMAL, $result->strictSolverStatus);
        self::assertSame(CoverageStatus::COMPLETE, $result->coverageStatus);
        self::assertSame(PlanningGenerationStatus::COMPLETED, $generation->getStatus());

        $assignments = $dutyAssignmentRepository->findByGeneration($generation);
        self::assertCount(2, $assignments);
        foreach ($assignments as $assignment) {
            self::assertSame(DutyAssignmentSource::AUTO, $assignment->getSource());
            self::assertFalse($assignment->isLocked());
        }
    }

    public function testAutoAssignmentReferencesRealTeamMemberAndSnapshotMemberAndDuty(): void
    {
        [$service, $generation, $em, $dutyAssignmentRepository, , $planningPeriod] = $this->scenarioTwoCandidatesTwoDuties();

        $service->generate($generation);

        $assignments = $dutyAssignmentRepository->findByGeneration($generation);
        self::assertNotEmpty($assignments);
        foreach ($assignments as $assignment) {
            self::assertSame($generation, $assignment->getGeneration());
            self::assertSame($planningPeriod, $assignment->getDuty()->getPlanningPeriod());
            self::assertTrue(
                $assignment->getSnapshotMember()->getSourceTeamMemberStableId()->equals($assignment->getTeamMember()->getStableId()),
                'the persisted snapshotMember must correspond to the persisted teamMember',
            );
        }
    }

    public function testGroupAssignmentCreatesOneDutyAssignmentPerConstituentDutySameCandidate(): void
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
        $generationService = $c->get(PlanningGenerationService::class);
        $dutyAssignmentRepository = $c->get(DutyAssignmentRepository::class);

        $creator = $this->createUser($em);
        $planning = $this->createPlanning($planningService, $creator, 'Seniors');
        $line = $lineRepository->findByPlanning($planning)[0];
        $team = $line->getPlanningTeam();
        $planningPeriod = $line->getPlanningPeriod();
        $this->activateRuleSet($ruleSetService, $team);

        $user = $this->createUser($em);
        $this->addMember($membershipService, $team, $user, TeamMemberRole::MEMBER, '2027-01-01', 1.0);

        [$group] = $this->createTwoDutyGroup(
            $em,
            $dutyMaterialization,
            $team,
            $planningPeriod,
            '2027-03-12',
            '2027-03-12 08:00',
            '2027-03-12 20:00',
            '2027-03-13 08:00',
            '2027-03-13 20:00',
        );

        $generation = $generationService->create($planningPeriod, $creator);
        $snapshotService->createSnapshot($generation);

        $result = $generationService->generate($generation);

        self::assertSame(SolverStatus::OPTIMAL, $result->strictSolverStatus);
        $assignments = $dutyAssignmentRepository->findByGeneration($generation);
        $groupDutyIds = array_map(static fn (Duty $d) => (string) $d->getStableId(), $group->getDuties()->toArray());
        $groupAssignments = array_values(array_filter($assignments, static fn ($a) => \in_array((string) $a->getDuty()->getStableId(), $groupDutyIds, true)));

        self::assertCount(2, $groupAssignments, 'both constituent Duty of the group must get their own DutyAssignment');
        $candidateIds = array_unique(array_map(static fn ($a) => (string) $a->getTeamMember()->getStableId(), $groupAssignments));
        self::assertCount(1, $candidateIds, 'both constituent Duty must go to the same candidate — the group is one atomic unit');
    }

    public function testPartialIncompletePersistsOnlyRealAssignmentsNeverAFakeOne(): void
    {
        [$service, $generation, $em, $dutyAssignmentRepository] = $this->scenarioTwoOverlappingRequiredDutiesOneCandidate();

        $result = $service->generate($generation);

        self::assertSame(SolverStatus::UNSATISFIABLE, $result->strictSolverStatus);
        self::assertNotNull($result->partialSolverStatus);
        self::assertSame(CoverageStatus::INCOMPLETE, $result->coverageStatus);
        self::assertSame(PlanningGenerationStatus::COMPLETED, $generation->getStatus(), 'a real INCOMPLETE PARTIAL result is a valid business outcome, never FAILED');
        self::assertCount(1, $result->unassignedDuties);

        $assignments = $dutyAssignmentRepository->findByGeneration($generation);
        self::assertCount(1, $assignments, 'only the duty CP-SAT actually assigned gets a DutyAssignment — the unassigned one gets none');
    }

    /**
     * docs/decisions.md D106 §18 — everything (DutyAssignment rows,
     * generation metadata, status transition) lives in exactly one
     * flush(). Forcing the unique constraint on (generation_id, duty_id)
     * to fail partway through the batch (by pre-creating a MANUAL
     * assignment for a Duty CP-SAT will also try to auto-assign) must
     * leave zero AUTO rows and the generation still SOLVING at the DB
     * level — never 1-of-2 assignments, never a COMPLETED status that was
     * never actually committed.
     */
    public function testFailureMidBatchRollsBackEverythingAtomically(): void
    {
        [$service, $generation, $em, $dutyAssignmentRepository, $teamMemberRepository, $planningPeriod] = $this->scenarioTwoCandidatesTwoDuties();

        $duties = self::getContainer()->get(DutyRepository::class)->findByPlanningPeriod($planningPeriod);
        self::assertCount(2, $duties);
        $anyMember = $teamMemberRepository->findBy(['planningTeam' => $planningPeriod->getTeam()])[0];

        $assignmentService = self::getContainer()->get(DutyAssignmentService::class);
        // Pre-create a MANUAL assignment for one of the two duties — CP-SAT
        // will independently choose to cover this same duty during
        // generate(), so the batch's createAuto() for it collides with this
        // row's (generation_id, duty_id) unique constraint at flush() time.
        $assignmentService->createManual($generation, $duties[0], $anyMember, false);

        $generationId = $generation->getId();

        try {
            $service->generate($generation);
            self::fail('Expected a unique constraint violation to propagate.');
        } catch (\Throwable) {
            // Expected — the exact exception type (DBAL unique violation)
            // is an implementation detail; what matters is provable below.
        }

        $em->clear();
        $freshGeneration = self::getContainer()->get(PlanningGenerationRepository::class)->find($generationId);
        self::assertSame(PlanningGenerationStatus::SOLVING, $freshGeneration->getStatus(), 'the SOLVING claim itself (its own separate flush) is the only thing that persisted — the big batch never committed');
        self::assertNull($freshGeneration->getAlgorithmVersion(), 'recordSolverRun() was never actually committed');

        $autoAssignments = array_filter($dutyAssignmentRepository->findByGeneration($freshGeneration), static fn ($a) => DutyAssignmentSource::AUTO === $a->getSource());
        self::assertCount(0, $autoAssignments, 'zero AUTO rows — not 1 of the 2 CP-SAT actually decided on');
    }

    // --- failure outcomes: never any DutyAssignment ---------------------

    public function testUnknownStrictStatusPersistsNoAssignmentAndMarksFailed(): void
    {
        $fakeResult = new OptimizationResult(SolverStatus::UNKNOWN, null, CoverageStatus::INCOMPLETE, [], [], [], [], null, null, null);
        [$service, $generation, $em, $dutyAssignmentRepository] = $this->scenarioWithFakeSolver($fakeResult);

        $result = $service->generate($generation);

        self::assertSame(SolverStatus::UNKNOWN, $result->strictSolverStatus);
        self::assertSame(PlanningGenerationStatus::FAILED, $generation->getStatus());
        self::assertNotNull($generation->getFailureReason());
        self::assertSame([], $dutyAssignmentRepository->findByGeneration($generation));
        self::assertSame(PlanningPeriodStatus::DRAFT, $generation->getPlanningPeriod()->getStatus(), 'a FAILED generation must never move PlanningPeriod to GENERATED');
    }

    public function testErrorStrictStatusPersistsNoAssignmentAndMarksFailed(): void
    {
        $fakeResult = new OptimizationResult(SolverStatus::ERROR, null, CoverageStatus::INCOMPLETE, [], [], [], [], null, null, null);
        [$service, $generation, $em, $dutyAssignmentRepository] = $this->scenarioWithFakeSolver($fakeResult);

        $result = $service->generate($generation);

        self::assertSame(SolverStatus::ERROR, $result->strictSolverStatus);
        self::assertSame(PlanningGenerationStatus::FAILED, $generation->getStatus());
        self::assertSame([], $dutyAssignmentRepository->findByGeneration($generation));
    }

    public function testPartialUnknownAfterStrictUnsatPersistsNoAssignmentAndMarksFailed(): void
    {
        $fakeResult = new OptimizationResult(SolverStatus::UNSATISFIABLE, SolverStatus::UNKNOWN, CoverageStatus::INCOMPLETE, [], [], [], [], null, null, null);
        [$service, $generation, $em, $dutyAssignmentRepository] = $this->scenarioWithFakeSolver($fakeResult);

        $result = $service->generate($generation);

        self::assertSame(SolverStatus::UNSATISFIABLE, $result->strictSolverStatus);
        self::assertSame(SolverStatus::UNKNOWN, $result->partialSolverStatus);
        self::assertSame(PlanningGenerationStatus::FAILED, $generation->getStatus());
        self::assertSame([], $dutyAssignmentRepository->findByGeneration($generation));
    }

    // --- idempotence / concurrency ---------------------------------------

    /**
     * The practical, deterministic proxy for a true concurrent race
     * (two real parallel requests are not reproducible inside one PHPUnit
     * process/transaction) — same convention already established by
     * PlanningSnapshotService's own tests: the sequential double-call
     * exercises exactly the DB-level guarantee (here, PlanningGeneration's
     * optimistic lock) that would also stop a true race, since the second
     * call's status check/claim attempt runs against the row the first
     * call already committed.
     */
    public function testSecondSolveOfTheSameGenerationIsRejectedNeverDuplicatingAssignments(): void
    {
        [$service, $generation, $em, $dutyAssignmentRepository] = $this->scenarioTwoCandidatesTwoDuties();

        $service->generate($generation);
        $countAfterFirst = \count($dutyAssignmentRepository->findByGeneration($generation));
        self::assertGreaterThan(0, $countAfterFirst);

        $this->expectException(PlanningGenerationConcurrentSolveException::class);
        try {
            $service->generate($generation);
        } finally {
            self::assertSame($countAfterFirst, \count($dutyAssignmentRepository->findByGeneration($generation)), 'a rejected second solve must never add or duplicate assignments');
        }
    }

    public function testTwoGenerationsOfTheSamePlanningPeriodCoexist(): void
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
        $generationService = $c->get(PlanningGenerationService::class);
        $dutyAssignmentRepository = $c->get(DutyAssignmentRepository::class);

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

        $generationA = $generationService->create($planningPeriod, $creator);
        $snapshotService->createSnapshot($generationA);
        $generationService->generate($generationA);

        $generationB = $generationService->create($planningPeriod, $creator);
        $snapshotService->createSnapshot($generationB);
        $this->createDuty($dutyMaterialization, $planningPeriod, $dutyType, '2027-02-08 08:00', '2027-02-08 20:00');
        $generationService->generate($generationB);

        self::assertSame(PlanningGenerationStatus::COMPLETED, $generationA->getStatus());
        self::assertSame(PlanningGenerationStatus::COMPLETED, $generationB->getStatus());

        $assignmentsA = $dutyAssignmentRepository->findByGeneration($generationA);
        $assignmentsB = $dutyAssignmentRepository->findByGeneration($generationB);
        self::assertCount(1, $assignmentsA);
        self::assertCount(2, $assignmentsB, 'generation B was snapshotted+solved after a second Duty existed — it must see it, generation A must not');
    }

    // --- historique / snapshot immutability -------------------------------

    public function testALaterGenerationsSolveNeverChangesAnEarlierOnesPersistedMetadata(): void
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
        $generationService = $c->get(PlanningGenerationService::class);

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

        $generationA = $generationService->create($planningPeriod, $creator);
        $snapshotService->createSnapshot($generationA);
        $generationService->generate($generationA);

        $snapshotHashA = $generationA->getSnapshotHash();
        $seedA = $generationA->getSeed();
        $generatedAtA = $generationA->getGeneratedAt();

        // A second generation, with a new Duty, solved afterwards.
        $this->createDuty($dutyMaterialization, $planningPeriod, $dutyType, '2027-02-08 08:00', '2027-02-08 20:00');
        $generationB = $generationService->create($planningPeriod, $creator);
        $snapshotService->createSnapshot($generationB);
        $generationService->generate($generationB);

        self::assertSame($snapshotHashA, $generationA->getSnapshotHash(), 'generation A\'s own recorded snapshotHash must never change');
        self::assertSame($seedA, $generationA->getSeed());
        self::assertEquals($generatedAtA, $generationA->getGeneratedAt());
        self::assertNotSame($generationA->getSnapshotHash(), $generationB->getSnapshotHash(), 'generation B genuinely solved against different data — different hash');
    }

    // --- metadata fields --------------------------------------------------

    public function testRealSolverMetadataIsPersistedNoPlaceholders(): void
    {
        [$service, $generation] = $this->scenarioTwoCandidatesTwoDuties();

        $service->generate($generation);

        self::assertSame(OptimizationProblemBuilder::ALGORITHM_VERSION, $generation->getAlgorithmVersion());
        self::assertSame('OR-Tools CP-SAT', $generation->getSolverType());
        self::assertNotSame('', $generation->getSolverVersion(), 'the real ortools.__version__ string, never empty');
        self::assertNotNull($generation->getSolverParameterSet());
        self::assertSame(60, $generation->getSolverParameterSet()->getTimeoutSeconds());
        self::assertNotNull($generation->getSeed());
        self::assertNotNull($generation->getSnapshotHash());
        self::assertGreaterThanOrEqual(0, $generation->getSolveDurationMs());
        self::assertFalse($generation->isTimeoutHit());
        self::assertNotNull($generation->getGeneratedAt());
    }

    // --- helpers -----------------------------------------------------------

    /**
     * @return array{0: PlanningGenerationService, 1: PlanningGeneration, 2: EntityManagerInterface, 3: DutyAssignmentRepository, 4: PlanningTeamMemberRepository, 5: PlanningPeriod}
     */
    private function scenarioTwoCandidatesTwoDuties(bool $snapshot = true): array
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
        $generationService = $c->get(PlanningGenerationService::class);
        $dutyAssignmentRepository = $c->get(DutyAssignmentRepository::class);
        $teamMemberRepository = $c->get(PlanningTeamMemberRepository::class);

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

        $generation = $generationService->create($planningPeriod, $creator);
        if ($snapshot) {
            $snapshotService->createSnapshot($generation);
        }

        return [$generationService, $generation, $em, $dutyAssignmentRepository, $teamMemberRepository, $planningPeriod];
    }

    /**
     * @return array{0: PlanningGenerationService, 1: PlanningGeneration, 2: EntityManagerInterface, 3: DutyAssignmentRepository}
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
        $generationService = $c->get(PlanningGenerationService::class);
        $dutyAssignmentRepository = $c->get(DutyAssignmentRepository::class);

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

        $generation = $generationService->create($planningPeriod, $creator);
        $snapshotService->createSnapshot($generation);

        return [$generationService, $generation, $em, $dutyAssignmentRepository];
    }

    /**
     * A real snapshot/generation pipeline, but with PlanningGenerationService
     * wired to a FakePlanningSolver returning a caller-chosen
     * OptimizationResult — the only tractable way to exercise
     * UNKNOWN/ERROR/partial-failure outcomes deterministically (real CP-SAT
     * cannot be reliably forced into them, docs/planning-solver.md §19).
     *
     * @return array{0: PlanningGenerationService, 1: PlanningGeneration, 2: EntityManagerInterface, 3: DutyAssignmentRepository}
     */
    private function scenarioWithFakeSolver(OptimizationResult $fakeResult): array
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
        $dutyAssignmentRepository = $c->get(DutyAssignmentRepository::class);

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

        $generation = $c->get(PlanningGenerationService::class)->create($planningPeriod, $creator);
        $snapshotService->createSnapshot($generation);

        $service = new PlanningGenerationService(
            $em,
            $c->get(PlanningSnapshotRepository::class),
            $c->get(PlanningSnapshotRuleSetRepository::class),
            $c->get(PlanningTeamMemberRepository::class),
            $c->get(SolverParameterSetRepository::class),
            $c->get(EligibilityMatrixBuilder::class),
            $c->get(FairnessContextBuilder::class),
            $c->get(OptimizationProblemBuilder::class),
            new FakePlanningSolver($fakeResult),
            $c->get(SnapshotHasher::class),
            $c->get(SeedMaterialBuilder::class),
            $c->get(DutyAssignmentService::class),
            $c->get(UnsatReportPresenter::class),
        );

        return [$service, $generation, $em, $dutyAssignmentRepository];
    }
}
