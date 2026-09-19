<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\DutyDemandType;
use App\Entity\SolverParameterSet;
use App\Entity\TeamMemberRole;
use App\Fairness\FairnessDimensionKey;
use App\Fairness\ObjectivePhaseId;
use App\Fairness\OptimizationMode;
use App\Repository\PlanningLineRepository;
use App\Service\DutyMaterializationService;
use App\Service\EligibilityMatrixBuilder;
use App\Service\FairnessContextBuilder;
use App\Service\OptimizationProblemBuilder;
use App\Service\PlanningLineService;
use App\Service\PlanningRuleSetService;
use App\Service\PlanningService;
use App\Service\PlanningSnapshotService;
use App\Service\PlanningTeamMembershipService;
use App\Tests\FairnessTestHelpers;
use App\Tests\PlanningDomainTestHelpers;
use App\Tests\PlanningGenerationTestHelpers;
use App\Tests\PlanningTestHelpers;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class OptimizationProblemBuilderTest extends KernelTestCase
{
    use FairnessTestHelpers;
    use PlanningDomainTestHelpers;
    use PlanningGenerationTestHelpers;
    use PlanningTestHelpers;

    public function testProducesOnlyModeGenerateWithNoSolverData(): void
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

        $context = $this->buildFairnessContext($em, $snapshotService, $matrixBuilder, $contextBuilder, $planningPeriod);
        $problem = $problemBuilder->build($context, new SolverParameterSet(1, 30, 1));

        self::assertSame(OptimizationMode::GENERATE, $problem->getMode());
    }

    public function testRequiredAndOptionalDutyUnitsAreCorrectlySeparated(): void
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

        $creator = $this->createUser($em);
        $planning = $this->createPlanning($planningService, $creator, 'Seniors');
        $line = $lineRepository->findByPlanning($planning)[0];
        $team = $line->getPlanningTeam();
        $planningPeriod = $line->getPlanningPeriod();
        $this->activateRuleSet($ruleSetService, $team);

        $user = $this->createUser($em);
        $this->addMember($membershipService, $team, $user, TeamMemberRole::MEMBER, '2027-01-01', 1.0);

        $dutyType = $this->createDutyType($em, $team);
        $requiredDuty = $this->createDuty($dutyMaterialization, $planningPeriod, $dutyType, '2027-02-01 08:00', '2027-02-01 20:00');
        $optionalDuty = $dutyMaterialization->createStandaloneDuty($planningPeriod, $dutyType, $this->date('2027-02-02 08:00'), $this->date('2027-02-02 20:00'), DutyDemandType::OPTIONAL);

        $context = $this->buildFairnessContext($em, $snapshotService, $matrixBuilder, $contextBuilder, $planningPeriod);
        $problem = $problemBuilder->build($context, new SolverParameterSet(1, 30, 1));

        $requiredKeys = array_map(static fn ($unit) => $unit->getStableKey(), $problem->getRequiredDutyUnits());
        $optionalKeys = array_map(static fn ($unit) => $unit->getStableKey(), $problem->getOptionalDutyUnits());

        self::assertContains((string) $requiredDuty->getStableId(), $requiredKeys);
        self::assertNotContains((string) $requiredDuty->getStableId(), $optionalKeys);
        self::assertContains((string) $optionalDuty->getStableId(), $optionalKeys);
        self::assertNotContains((string) $optionalDuty->getStableId(), $requiredKeys);
    }

    public function testNeverContainsADutyFromAnotherPlanningLine(): void
    {
        self::bootKernel();
        $c = self::getContainer();
        $em = $c->get(EntityManagerInterface::class);
        $planningService = $c->get(PlanningService::class);
        $lineService = $c->get(PlanningLineService::class);
        $lineRepository = $c->get(PlanningLineRepository::class);
        $membershipService = $c->get(PlanningTeamMembershipService::class);
        $ruleSetService = $c->get(PlanningRuleSetService::class);
        $dutyMaterialization = $c->get(DutyMaterializationService::class);
        $snapshotService = $c->get(PlanningSnapshotService::class);
        $matrixBuilder = $c->get(EligibilityMatrixBuilder::class);
        $contextBuilder = $c->get(FairnessContextBuilder::class);
        $problemBuilder = $c->get(OptimizationProblemBuilder::class);

        $creator = $this->createUser($em);
        $planning = $this->createPlanning($planningService, $creator, 'Seniors');
        $lineA = $lineRepository->findByPlanning($planning)[0];
        $lineB = $this->addLine($lineService, $planning, 'Assistants');

        $this->activateRuleSet($ruleSetService, $lineA->getPlanningTeam());
        $this->activateRuleSet($ruleSetService, $lineB->getPlanningTeam());

        $userA = $this->createUser($em);
        $userB = $this->createUser($em);
        $this->addMember($membershipService, $lineA->getPlanningTeam(), $userA, TeamMemberRole::MEMBER, '2027-01-01', 1.0);
        $this->addMember($membershipService, $lineB->getPlanningTeam(), $userB, TeamMemberRole::MEMBER, '2027-01-01', 1.0);

        $dutyTypeA = $this->createDutyType($em, $lineA->getPlanningTeam());
        $dutyTypeB = $this->createDutyType($em, $lineB->getPlanningTeam());
        $this->createDuty($dutyMaterialization, $lineA->getPlanningPeriod(), $dutyTypeA, '2027-02-01 08:00', '2027-02-01 20:00');
        $dutyB = $this->createDuty($dutyMaterialization, $lineB->getPlanningPeriod(), $dutyTypeB, '2027-02-01 08:00', '2027-02-01 20:00');

        $contextA = $this->buildFairnessContext($em, $snapshotService, $matrixBuilder, $contextBuilder, $lineA->getPlanningPeriod());
        $problemA = $problemBuilder->build($contextA, new SolverParameterSet(1, 30, 1));

        $allKeysInA = array_map(
            static fn ($unit) => $unit->getStableKey(),
            [...$problemA->getRequiredDutyUnits(), ...$problemA->getOptionalDutyUnits()],
        );

        self::assertNotContains((string) $dutyB->getStableId(), $allKeysInA, 'line B\'s Duty must never leak into line A\'s OptimizationProblem');
        self::assertSame(0.0, $problemA->getFairnessTarget((string) $userB->getStableId())->get(FairnessDimensionKey::totalDuties()), 'userB (never a member of line A\'s team) must contribute nothing to line A\'s problem');
    }

    public function testObjectivePhasesAreBuiltForGenerateModeWithEightPhases(): void
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

        $context = $this->buildFairnessContext($em, $snapshotService, $matrixBuilder, $contextBuilder, $planningPeriod);
        $problem = $problemBuilder->build($context, new SolverParameterSet(1, 30, 1));

        $phases = $problem->getObjectivePhases();

        self::assertCount(8, $phases);
        self::assertSame(ObjectivePhaseId::DETERMINISTIC_TIE_BREAK, $phases[7]->id);
        // The Duty just created is a real supported/applicable dimension
        // (TOTAL_DUTIES etc.), all classified SECONDARY today (D086) — the
        // SECONDARY phases must therefore not be empty here.
        self::assertNotSame([], $phases[2]->dimensions, 'max deviation SECONDARY');
        self::assertNotSame([], $phases[3]->dimensions, 'sum deviation SECONDARY');
    }
}
