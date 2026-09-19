<?php

declare(strict_types=1);

namespace App\Tests\Solver;

use App\Entity\TeamMemberRole;
use App\Entity\UserAvailabilityType;
use App\Fairness\ObjectivePhaseId;
use App\Repository\PlanningLineRepository;
use App\Service\DutyMaterializationService;
use App\Service\EligibilityMatrixBuilder;
use App\Service\FairnessContextBuilder;
use App\Service\OptimizationProblemBuilder;
use App\Service\PlanningRuleSetService;
use App\Service\PlanningService;
use App\Service\PlanningSnapshotService;
use App\Service\PlanningTeamMembershipService;
use App\Service\UserAvailabilityService;
use App\Solver\CpSatPayloadBuilder;
use App\Tests\PlanningDomainTestHelpers;
use App\Tests\PlanningGenerationTestHelpers;
use App\Tests\PlanningTestHelpers;
use App\Tests\SolverTestHelpers;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CpSatPayloadBuilderTest extends KernelTestCase
{
    use PlanningDomainTestHelpers;
    use PlanningGenerationTestHelpers;
    use PlanningTestHelpers;
    use SolverTestHelpers;

    public function testVariableExistsOnlyForAnEligiblePair(): void
    {
        self::bootKernel();
        $c = self::getContainer();
        $em = $c->get(EntityManagerInterface::class);
        $planningService = $c->get(PlanningService::class);
        $lineRepository = $c->get(PlanningLineRepository::class);
        $membershipService = $c->get(PlanningTeamMembershipService::class);
        $ruleSetService = $c->get(PlanningRuleSetService::class);
        $dutyMaterialization = $c->get(DutyMaterializationService::class);
        $availabilityService = $c->get(UserAvailabilityService::class);
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

        $eligibleUser = $this->createUser($em);
        $ineligibleUser = $this->createUser($em);
        $eligibleMember = $this->addMember($membershipService, $team, $eligibleUser, TeamMemberRole::MEMBER, '2027-01-01', 1.0);
        $ineligibleMember = $this->addMember($membershipService, $team, $ineligibleUser, TeamMemberRole::MEMBER, '2027-01-01', 1.0);

        // UNAVAILABLE is a HARD exclusion (eligible = false) even though it
        // never reduces structuralOpportunity (docs/eligibility.md §4) —
        // exactly the "ineligible pair" case this test needs.
        $this->addAvailability($availabilityService, $ineligibleUser, UserAvailabilityType::UNAVAILABLE, '2027-02-01 00:00', '2027-02-02 00:00');

        $dutyType = $this->createDutyType($em, $team);
        $duty = $this->createDuty($dutyMaterialization, $planningPeriod, $dutyType, '2027-02-01 08:00', '2027-02-01 20:00');

        $problem = $this->buildOptimizationProblem($em, $snapshotService, $matrixBuilder, $contextBuilder, $problemBuilder, $planningPeriod);
        $payload = $payloadBuilder->buildSolvePayload($problem);

        $unitEntry = $this->findUnit($payload, (string) $duty->getStableId());

        self::assertContains((string) $eligibleMember->getStableId(), $unitEntry['eligibleCandidates'], 'the eligible member must get a variable');
        self::assertNotContains((string) $ineligibleMember->getStableId(), $unitEntry['eligibleCandidates'], 'the UNAVAILABLE member must never get a variable — infeasible by construction');
    }

    public function testGroupIsRepresentedAsExactlyOneUnitNeverOnePerConstituentDuty(): void
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

        $user = $this->createUser($em);
        $this->addMember($membershipService, $team, $user, TeamMemberRole::MEMBER, '2027-01-01', 1.0);

        [$group] = $this->createTwoDutyGroup(
            $em,
            $dutyMaterialization,
            $team,
            $planningPeriod,
            '2027-02-06',
            '2027-02-06 08:00',
            '2027-02-06 20:00',
            '2027-02-07 08:00',
            '2027-02-07 20:00',
        );

        $problem = $this->buildOptimizationProblem($em, $snapshotService, $matrixBuilder, $contextBuilder, $problemBuilder, $planningPeriod);
        $payload = $payloadBuilder->buildSolvePayload($problem);

        $groupKey = (string) $group->getStableId();
        $matchingUnits = array_values(array_filter($payload['dutyUnits'], static fn (array $u): bool => $u['key'] === $groupKey));

        self::assertCount(1, $matchingUnits, 'a DutyGroupInstance must be exactly one CP-SAT unit, never one per constituent Duty');
    }

    public function testZeroFairnessTargetNeverDividesByZero(): void
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

        // A member joining after every Duty of the period has structural
        // exposure 0 on every dimension -> fairnessTarget = 0 exactly
        // (docs/fairness.md §7) — the scenario this test needs.
        $user = $this->createUser($em);
        $this->addMember($membershipService, $team, $user, TeamMemberRole::MEMBER, '2027-04-30', 1.0);

        $dutyType = $this->createDutyType($em, $team);
        $this->createDuty($dutyMaterialization, $planningPeriod, $dutyType, '2027-02-01 08:00', '2027-02-01 20:00');

        $problem = $this->buildOptimizationProblem($em, $snapshotService, $matrixBuilder, $contextBuilder, $problemBuilder, $planningPeriod);

        // Must not throw (division by zero) and must produce a well-formed payload.
        $payload = $payloadBuilder->buildSolvePayload($problem);

        self::assertIsArray($payload);
        $secondaryPhase = $this->findPhase($payload, ObjectivePhaseId::MAX_DEVIATION_SECONDARY);
        self::assertIsArray($secondaryPhase);
    }

    public function testFeasibilityPayloadNeverIncludesPhases(): void
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

        $user = $this->createUser($em);
        $this->addMember($membershipService, $team, $user, TeamMemberRole::MEMBER, '2027-01-01', 1.0);
        $dutyType = $this->createDutyType($em, $team);
        $this->createDuty($dutyMaterialization, $planningPeriod, $dutyType, '2027-02-01 08:00', '2027-02-01 20:00');

        $problem = $this->buildOptimizationProblem($em, $snapshotService, $matrixBuilder, $contextBuilder, $problemBuilder, $planningPeriod);
        $payload = $payloadBuilder->buildFeasibilityPayload($problem, []);

        self::assertSame([], $payload['phases']);
        self::assertTrue($payload['feasibilityOnly']);
    }

    public function testFixedAssignmentsAreAlwaysEmptyNoConceptExistsYet(): void
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
        $payloadBuilder = $c->get(CpSatPayloadBuilder::class);

        $creator = $this->createUser($em);
        $planning = $this->createPlanning($planningService, $creator, 'Seniors');
        $line = $lineRepository->findByPlanning($planning)[0];
        $team = $line->getPlanningTeam();
        $planningPeriod = $line->getPlanningPeriod();
        $this->activateRuleSet($ruleSetService, $team);

        $problem = $this->buildOptimizationProblem($em, $snapshotService, $matrixBuilder, $contextBuilder, $problemBuilder, $planningPeriod);
        $payload = $payloadBuilder->buildSolvePayload($problem);

        // docs/decisions.md D090: no OptimizationProblem carries
        // fixedAssignments yet — never fabricated by the adapter.
        self::assertSame([], $payload['excludedEdges']);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array{key: string, required: bool, eligibleCandidates: list<string>}
     */
    private function findUnit(array $payload, string $key): array
    {
        foreach ($payload['dutyUnits'] as $unit) {
            if ($unit['key'] === $key) {
                return $unit;
            }
        }

        self::fail(sprintf('no duty unit found with key %s', $key));
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>|null
     */
    private function findPhase(array $payload, ObjectivePhaseId $id): ?array
    {
        foreach ($payload['phases'] as $phase) {
            if ($phase['id'] === $id->value) {
                return $phase;
            }
        }

        return null;
    }
}
