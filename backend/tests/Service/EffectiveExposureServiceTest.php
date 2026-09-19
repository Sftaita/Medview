<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\ParticipationFactorChangeReason;
use App\Entity\TeamMemberRole;
use App\Entity\UserAvailabilityType;
use App\Fairness\FairnessDimensionKey;
use App\Repository\PlanningLineRepository;
use App\Service\DutyMaterializationService;
use App\Service\EligibilityMatrixBuilder;
use App\Service\FairnessContextBuilder;
use App\Service\ParticipationPeriodService;
use App\Service\PlanningRuleSetService;
use App\Service\PlanningService;
use App\Service\PlanningSnapshotService;
use App\Service\PlanningTeamMembershipService;
use App\Service\TeamMemberNonParticipationService;
use App\Service\UserAvailabilityService;
use App\Tests\FairnessTestHelpers;
use App\Tests\PlanningDomainTestHelpers;
use App\Tests\PlanningGenerationTestHelpers;
use App\Tests\PlanningTestHelpers;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class EffectiveExposureServiceTest extends KernelTestCase
{
    use FairnessTestHelpers;
    use PlanningDomainTestHelpers;
    use PlanningGenerationTestHelpers;
    use PlanningTestHelpers;

    public function testConstantFactorOneContributesFullWeight(): void
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

        self::assertSame(1.0, $context->getEffectiveExposure((string) $user->getStableId())->get(FairnessDimensionKey::totalDuties()));
    }

    public function testConstantFactorHalfScalesExposureAccordingly(): void
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

        $creator = $this->createUser($em);
        $planning = $this->createPlanning($planningService, $creator, 'Seniors');
        $line = $lineRepository->findByPlanning($planning)[0];
        $team = $line->getPlanningTeam();
        $planningPeriod = $line->getPlanningPeriod();

        $this->activateRuleSet($ruleSetService, $team);
        $user = $this->createUser($em);
        $this->addMember($membershipService, $team, $user, TeamMemberRole::MEMBER, '2027-01-01', 0.5);

        $dutyType = $this->createDutyType($em, $team);
        $this->createDuty($dutyMaterialization, $planningPeriod, $dutyType, '2027-02-01 08:00', '2027-02-01 20:00');

        $context = $this->buildFairnessContext($em, $snapshotService, $matrixBuilder, $contextBuilder, $planningPeriod);

        self::assertSame(0.5, $context->getEffectiveExposure((string) $user->getStableId())->get(FairnessDimensionKey::totalDuties()));
    }

    public function testFactorChangeMidPeriodIsAppliedPerDutyDate(): void
    {
        self::bootKernel();
        $c = self::getContainer();
        $em = $c->get(EntityManagerInterface::class);
        $planningService = $c->get(PlanningService::class);
        $lineRepository = $c->get(PlanningLineRepository::class);
        $membershipService = $c->get(PlanningTeamMembershipService::class);
        $participationPeriodService = $c->get(ParticipationPeriodService::class);
        $ruleSetService = $c->get(PlanningRuleSetService::class);
        $dutyMaterialization = $c->get(DutyMaterializationService::class);
        $snapshotService = $c->get(PlanningSnapshotService::class);
        $matrixBuilder = $c->get(EligibilityMatrixBuilder::class);
        $contextBuilder = $c->get(FairnessContextBuilder::class);

        $creator = $this->createUser($em);
        $planning = $this->createPlanning($planningService, $creator, 'Seniors');
        $line = $lineRepository->findByPlanning($planning)[0];
        $team = $line->getPlanningTeam();
        $planningPeriod = $line->getPlanningPeriod();

        $this->activateRuleSet($ruleSetService, $team);
        $user = $this->createUser($em);
        $member = $this->addMember($membershipService, $team, $user, TeamMemberRole::MEMBER, '2027-01-01', 1.0);
        // 01→15 Feb: factor 1.0 (initial) ; 15 Feb→end: factor 0.5.
        $participationPeriodService->changeFactor($member, $this->date('2027-02-15'), 0.5, ParticipationFactorChangeReason::CONTRACTUAL_CHANGE);

        $dutyType = $this->createDutyType($em, $team);
        // Before the change: full factor.
        $this->createDuty($dutyMaterialization, $planningPeriod, $dutyType, '2027-02-10 08:00', '2027-02-10 20:00');
        // On/after the change: half factor.
        $this->createDuty($dutyMaterialization, $planningPeriod, $dutyType, '2027-02-20 08:00', '2027-02-20 20:00');

        $context = $this->buildFairnessContext($em, $snapshotService, $matrixBuilder, $contextBuilder, $planningPeriod);

        self::assertSame(1.5, $context->getEffectiveExposure((string) $user->getStableId())->get(FairnessDimensionKey::totalDuties()), '1.0 (before) + 0.5 (after) — never a flat average, never the start/end-of-period value applied to both');
    }

    public function testDutyOutsideMembershipWindowContributesNothing(): void
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

        $creator = $this->createUser($em);
        $planning = $this->createPlanning($planningService, $creator, 'Seniors');
        $line = $lineRepository->findByPlanning($planning)[0];
        $team = $line->getPlanningTeam();
        $planningPeriod = $line->getPlanningPeriod();

        $this->activateRuleSet($ruleSetService, $team);
        $user = $this->createUser($em);
        // Joins only from 1 March — the 1 February duty falls before membershipStart.
        $this->addMember($membershipService, $team, $user, TeamMemberRole::MEMBER, '2027-03-01', 1.0);

        $dutyType = $this->createDutyType($em, $team);
        $this->createDuty($dutyMaterialization, $planningPeriod, $dutyType, '2027-02-01 08:00', '2027-02-01 20:00');

        $context = $this->buildFairnessContext($em, $snapshotService, $matrixBuilder, $contextBuilder, $planningPeriod);

        self::assertSame(0.0, $context->getEffectiveExposure((string) $user->getStableId())->get(FairnessDimensionKey::totalDuties()));
    }

    public function testNonParticipationZeroesExposureForAffectedDuties(): void
    {
        self::bootKernel();
        $c = self::getContainer();
        $em = $c->get(EntityManagerInterface::class);
        $planningService = $c->get(PlanningService::class);
        $lineRepository = $c->get(PlanningLineRepository::class);
        $membershipService = $c->get(PlanningTeamMembershipService::class);
        $nonParticipationService = $c->get(TeamMemberNonParticipationService::class);
        $ruleSetService = $c->get(PlanningRuleSetService::class);
        $dutyMaterialization = $c->get(DutyMaterializationService::class);
        $snapshotService = $c->get(PlanningSnapshotService::class);
        $matrixBuilder = $c->get(EligibilityMatrixBuilder::class);
        $contextBuilder = $c->get(FairnessContextBuilder::class);

        $creator = $this->createUser($em);
        $planning = $this->createPlanning($planningService, $creator, 'Seniors');
        $line = $lineRepository->findByPlanning($planning)[0];
        $team = $line->getPlanningTeam();
        $planningPeriod = $line->getPlanningPeriod();

        $this->activateRuleSet($ruleSetService, $team);
        $user = $this->createUser($em);
        $member = $this->addMember($membershipService, $team, $user, TeamMemberRole::MEMBER, '2027-01-01', 1.0);
        $this->addNonParticipation($nonParticipationService, $member, '2027-02-01 00:00', '2027-02-28 00:00');

        $dutyType = $this->createDutyType($em, $team);
        $this->createDuty($dutyMaterialization, $planningPeriod, $dutyType, '2027-02-10 08:00', '2027-02-10 20:00');

        $context = $this->buildFairnessContext($em, $snapshotService, $matrixBuilder, $contextBuilder, $planningPeriod);

        self::assertSame(0.0, $context->getEffectiveExposure((string) $user->getStableId())->get(FairnessDimensionKey::totalDuties()), 'NON_PARTICIPATION zeroes structuralOpportunity, docs/allocation-algorithm.md §20');
    }

    /**
     * Resistance-to-gaming invariant (docs/allocation-algorithm.md §20):
     * declaring a personal UNAVAILABLE must never reduce structuralOpportunity,
     * so it must never reduce effectiveExposure either.
     */
    public function testUnavailableNeverReducesExposure(): void
    {
        self::bootKernel();
        $c = self::getContainer();
        $em = $c->get(EntityManagerInterface::class);
        $planningService = $c->get(PlanningService::class);
        $lineRepository = $c->get(PlanningLineRepository::class);
        $membershipService = $c->get(PlanningTeamMembershipService::class);
        $availabilityService = $c->get(UserAvailabilityService::class);
        $ruleSetService = $c->get(PlanningRuleSetService::class);
        $dutyMaterialization = $c->get(DutyMaterializationService::class);
        $snapshotService = $c->get(PlanningSnapshotService::class);
        $matrixBuilder = $c->get(EligibilityMatrixBuilder::class);
        $contextBuilder = $c->get(FairnessContextBuilder::class);

        $creator = $this->createUser($em);
        $planning = $this->createPlanning($planningService, $creator, 'Seniors');
        $line = $lineRepository->findByPlanning($planning)[0];
        $team = $line->getPlanningTeam();
        $planningPeriod = $line->getPlanningPeriod();

        $this->activateRuleSet($ruleSetService, $team);
        $user = $this->createUser($em);
        $this->addMember($membershipService, $team, $user, TeamMemberRole::MEMBER, '2027-01-01', 1.0);
        $this->addAvailability($availabilityService, $user, UserAvailabilityType::UNAVAILABLE, '2027-02-10 00:00', '2027-02-11 00:00');

        $dutyType = $this->createDutyType($em, $team);
        $this->createDuty($dutyMaterialization, $planningPeriod, $dutyType, '2027-02-10 08:00', '2027-02-10 20:00');

        $context = $this->buildFairnessContext($em, $snapshotService, $matrixBuilder, $contextBuilder, $planningPeriod);

        self::assertSame(1.0, $context->getEffectiveExposure((string) $user->getStableId())->get(FairnessDimensionKey::totalDuties()), 'a personal UNAVAILABLE must never reduce exposure — only NON_PARTICIPATION/MEMBERSHIP_OUT_OF_RANGE/USER_INACTIVE do');
    }

    public function testPreferDutyHasNoImpactOnExposure(): void
    {
        self::bootKernel();
        $c = self::getContainer();
        $em = $c->get(EntityManagerInterface::class);
        $planningService = $c->get(PlanningService::class);
        $lineRepository = $c->get(PlanningLineRepository::class);
        $membershipService = $c->get(PlanningTeamMembershipService::class);
        $availabilityService = $c->get(UserAvailabilityService::class);
        $ruleSetService = $c->get(PlanningRuleSetService::class);
        $dutyMaterialization = $c->get(DutyMaterializationService::class);
        $snapshotService = $c->get(PlanningSnapshotService::class);
        $matrixBuilder = $c->get(EligibilityMatrixBuilder::class);
        $contextBuilder = $c->get(FairnessContextBuilder::class);

        $creator = $this->createUser($em);
        $planning = $this->createPlanning($planningService, $creator, 'Seniors');
        $line = $lineRepository->findByPlanning($planning)[0];
        $team = $line->getPlanningTeam();
        $planningPeriod = $line->getPlanningPeriod();

        $this->activateRuleSet($ruleSetService, $team);
        $user = $this->createUser($em);
        $this->addMember($membershipService, $team, $user, TeamMemberRole::MEMBER, '2027-01-01', 1.0);
        $this->addAvailability($availabilityService, $user, UserAvailabilityType::PREFER_DUTY, '2027-02-10 00:00', '2027-02-11 00:00');

        $dutyType = $this->createDutyType($em, $team);
        $this->createDuty($dutyMaterialization, $planningPeriod, $dutyType, '2027-02-10 08:00', '2027-02-10 20:00');

        $context = $this->buildFairnessContext($em, $snapshotService, $matrixBuilder, $contextBuilder, $planningPeriod);

        self::assertSame(1.0, $context->getEffectiveExposure((string) $user->getStableId())->get(FairnessDimensionKey::totalDuties()));
    }

    public function testExposureIsZeroWhenNoDutyIsEverStructurallyOpen(): void
    {
        self::bootKernel();
        $c = self::getContainer();
        $em = $c->get(EntityManagerInterface::class);
        $planningService = $c->get(PlanningService::class);
        $lineRepository = $c->get(PlanningLineRepository::class);
        $membershipService = $c->get(PlanningTeamMembershipService::class);
        $nonParticipationService = $c->get(TeamMemberNonParticipationService::class);
        $ruleSetService = $c->get(PlanningRuleSetService::class);
        $dutyMaterialization = $c->get(DutyMaterializationService::class);
        $snapshotService = $c->get(PlanningSnapshotService::class);
        $matrixBuilder = $c->get(EligibilityMatrixBuilder::class);
        $contextBuilder = $c->get(FairnessContextBuilder::class);

        $creator = $this->createUser($em);
        $planning = $this->createPlanning($planningService, $creator, 'Seniors');
        $line = $lineRepository->findByPlanning($planning)[0];
        $team = $line->getPlanningTeam();
        $planningPeriod = $line->getPlanningPeriod();

        $this->activateRuleSet($ruleSetService, $team);
        $user = $this->createUser($em);
        $member = $this->addMember($membershipService, $team, $user, TeamMemberRole::MEMBER, '2027-01-01', 1.0);
        // Non-participation covers the whole PlanningPeriod window.
        $this->addNonParticipation($nonParticipationService, $member, '2027-01-01 00:00', '2027-05-01 00:00');

        $dutyType = $this->createDutyType($em, $team);
        $this->createDuty($dutyMaterialization, $planningPeriod, $dutyType, '2027-02-10 08:00', '2027-02-10 20:00');

        $context = $this->buildFairnessContext($em, $snapshotService, $matrixBuilder, $contextBuilder, $planningPeriod);

        $exposure = $context->getEffectiveExposure((string) $user->getStableId());
        self::assertSame(0.0, $exposure->get(FairnessDimensionKey::totalDuties()));
        self::assertSame(0.0, $exposure->get(FairnessDimensionKey::weightedWorkload()));
    }
}
