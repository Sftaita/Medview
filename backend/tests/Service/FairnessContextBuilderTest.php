<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\TeamMemberRole;
use App\Entity\UserAvailabilityType;
use App\Fairness\FairnessDimensionKey;
use App\Repository\PlanningLineRepository;
use App\Service\DutyMaterializationService;
use App\Service\EligibilityMatrixBuilder;
use App\Service\FairnessContextBuilder;
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

/**
 * docs/decisions.md D082 Audit A + §11 "Audit multi-Planning obligatoire" —
 * fairness must stay strictly scoped per (PlanningLine, PlanningTeam), and
 * a User's multiple membership stints within one snapshot must collapse
 * into exactly one fairness candidate.
 */
final class FairnessContextBuilderTest extends KernelTestCase
{
    use FairnessTestHelpers;
    use PlanningDomainTestHelpers;
    use PlanningGenerationTestHelpers;
    use PlanningTestHelpers;

    public function testFairnessContextsOfDifferentPlanningsAreFullyIndependent(): void
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

        // Two different Plannings — createPlanning() with no shared
        // Planning argument means teamA/teamB belong to two different
        // Plannings by construction.
        $creatorA = $this->createUser($em);
        $planningA = $this->createPlanning($planningService, $creatorA, 'Team A');
        $lineA = $lineRepository->findByPlanning($planningA)[0];
        $teamA = $lineA->getPlanningTeam();
        $periodA = $lineA->getPlanningPeriod();

        $creatorB = $this->createUser($em);
        $planningB = $this->createPlanning($planningService, $creatorB, 'Team B');
        $lineB = $lineRepository->findByPlanning($planningB)[0];
        $teamB = $lineB->getPlanningTeam();
        $periodB = $lineB->getPlanningPeriod();

        self::assertNotSame($planningA->getStableId(), $planningB->getStableId());

        $this->activateRuleSet($ruleSetService, $teamA);
        $this->activateRuleSet($ruleSetService, $teamB);

        // The same User X is simultaneously a member of both — allowed
        // since D080 (docs/decisions.md): one open membership per Planning,
        // never per application.
        $userX = $this->createUser($em);
        $this->addMember($membershipService, $teamA, $userX, TeamMemberRole::MEMBER, '2027-01-01', 1.0);
        $this->addMember($membershipService, $teamB, $userX, TeamMemberRole::MEMBER, '2027-01-01', 1.0);

        $dutyTypeA = $this->createDutyType($em, $teamA, 'ONCALL_A');
        $dutyTypeB = $this->createDutyType($em, $teamB, 'ONCALL_B');
        // Two required duties in A, one in B — demand must never mix.
        $this->createDuty($dutyMaterialization, $periodA, $dutyTypeA, '2027-02-01 08:00', '2027-02-01 20:00');
        $this->createDuty($dutyMaterialization, $periodA, $dutyTypeA, '2027-02-02 08:00', '2027-02-02 20:00');
        $this->createDuty($dutyMaterialization, $periodB, $dutyTypeB, '2027-02-01 08:00', '2027-02-01 20:00');

        $contextA = $this->buildFairnessContext($em, $snapshotService, $matrixBuilder, $contextBuilder, $periodA);
        $contextB = $this->buildFairnessContext($em, $snapshotService, $matrixBuilder, $contextBuilder, $periodB);

        self::assertSame($lineA, $contextA->getPlanningLine());
        self::assertSame($teamA, $contextA->getPlanningTeam());
        self::assertSame($lineB, $contextB->getPlanningLine());
        self::assertSame($teamB, $contextB->getPlanningTeam());

        self::assertSame(2.0, $contextA->getRequiredDemand()->get(FairnessDimensionKey::totalDuties()));
        self::assertSame(1.0, $contextB->getRequiredDemand()->get(FairnessDimensionKey::totalDuties()));

        // User X's exposure in A only ever reflects A's own duties, never B's.
        self::assertSame(2.0, $contextA->getEffectiveExposure((string) $userX->getStableId())->get(FairnessDimensionKey::totalDuties()));
        self::assertSame(1.0, $contextB->getEffectiveExposure((string) $userX->getStableId())->get(FairnessDimensionKey::totalDuties()));

        // A's DutyType dimension must never appear in B's context and vice versa.
        self::assertSame(2.0, $contextA->getRequiredDemand()->get(FairnessDimensionKey::dutyType((string) $dutyTypeA->getStableId())));
        self::assertSame(0.0, $contextB->getRequiredDemand()->get(FairnessDimensionKey::dutyType((string) $dutyTypeA->getStableId())));
    }

    public function testPersonalNonParticipationInOnePlanningNeverAffectsTheOther(): void
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
        $nonParticipationService = $c->get(TeamMemberNonParticipationService::class);

        $creatorA = $this->createUser($em);
        $planningA = $this->createPlanning($planningService, $creatorA, 'Team A');
        $lineA = $lineRepository->findByPlanning($planningA)[0];
        $teamA = $lineA->getPlanningTeam();
        $periodA = $lineA->getPlanningPeriod();

        $creatorB = $this->createUser($em);
        $planningB = $this->createPlanning($planningService, $creatorB, 'Team B');
        $lineB = $lineRepository->findByPlanning($planningB)[0];
        $teamB = $lineB->getPlanningTeam();
        $periodB = $lineB->getPlanningPeriod();

        $this->activateRuleSet($ruleSetService, $teamA);
        $this->activateRuleSet($ruleSetService, $teamB);

        $userX = $this->createUser($em);
        $memberA = $this->addMember($membershipService, $teamA, $userX, TeamMemberRole::MEMBER, '2027-01-01', 1.0);
        $this->addMember($membershipService, $teamB, $userX, TeamMemberRole::MEMBER, '2027-01-01', 1.0);

        // Non-participation is attached to the *membership* (PlanningTeamMember), scoped to Planning A only.
        $this->addNonParticipation($nonParticipationService, $memberA, '2027-02-01 00:00', '2027-02-02 00:00');

        $dutyTypeA = $this->createDutyType($em, $teamA);
        $dutyTypeB = $this->createDutyType($em, $teamB);
        $this->createDuty($dutyMaterialization, $periodA, $dutyTypeA, '2027-02-01 08:00', '2027-02-01 20:00');
        $this->createDuty($dutyMaterialization, $periodB, $dutyTypeB, '2027-02-01 08:00', '2027-02-01 20:00');

        $contextA = $this->buildFairnessContext($em, $snapshotService, $matrixBuilder, $contextBuilder, $periodA);
        $contextB = $this->buildFairnessContext($em, $snapshotService, $matrixBuilder, $contextBuilder, $periodB);

        self::assertSame(0.0, $contextA->getEffectiveExposure((string) $userX->getStableId())->get(FairnessDimensionKey::totalDuties()), 'Planning A\'s non-participation must zero exposure there');
        self::assertSame(1.0, $contextB->getEffectiveExposure((string) $userX->getStableId())->get(FairnessDimensionKey::totalDuties()), 'Planning B must be completely unaffected by Planning A\'s non-participation');
    }

    public function testPersonalUserAvailabilityIsVisibleFromBothIndependentSnapshotsButNeverReducesExposure(): void
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
        $availabilityService = $c->get(UserAvailabilityService::class);

        $creatorA = $this->createUser($em);
        $planningA = $this->createPlanning($planningService, $creatorA, 'Team A');
        $lineA = $lineRepository->findByPlanning($planningA)[0];
        $teamA = $lineA->getPlanningTeam();
        $periodA = $lineA->getPlanningPeriod();

        $creatorB = $this->createUser($em);
        $planningB = $this->createPlanning($planningService, $creatorB, 'Team B');
        $lineB = $lineRepository->findByPlanning($planningB)[0];
        $teamB = $lineB->getPlanningTeam();
        $periodB = $lineB->getPlanningPeriod();

        $this->activateRuleSet($ruleSetService, $teamA);
        $this->activateRuleSet($ruleSetService, $teamB);

        $userX = $this->createUser($em);
        $this->addMember($membershipService, $teamA, $userX, TeamMemberRole::MEMBER, '2027-01-01', 1.0);
        $this->addMember($membershipService, $teamB, $userX, TeamMemberRole::MEMBER, '2027-01-01', 1.0);

        // One single personal declaration, belongs to the User, not to any one team.
        $this->addAvailability($availabilityService, $userX, UserAvailabilityType::UNAVAILABLE, '2027-02-01 00:00', '2027-02-02 00:00');

        $dutyTypeA = $this->createDutyType($em, $teamA);
        $dutyTypeB = $this->createDutyType($em, $teamB);
        $this->createDuty($dutyMaterialization, $periodA, $dutyTypeA, '2027-02-01 08:00', '2027-02-01 20:00');
        $this->createDuty($dutyMaterialization, $periodB, $dutyTypeB, '2027-02-01 08:00', '2027-02-01 20:00');

        $contextA = $this->buildFairnessContext($em, $snapshotService, $matrixBuilder, $contextBuilder, $periodA);
        $contextB = $this->buildFairnessContext($em, $snapshotService, $matrixBuilder, $contextBuilder, $periodB);

        // UNAVAILABLE never reduces structuralOpportunity (docs/allocation-algorithm.md §20) — true in both snapshots.
        self::assertSame(1.0, $contextA->getEffectiveExposure((string) $userX->getStableId())->get(FairnessDimensionKey::totalDuties()));
        self::assertSame(1.0, $contextB->getEffectiveExposure((string) $userX->getStableId())->get(FairnessDimensionKey::totalDuties()));
    }

    public function testMultipleHistoricalStintsOfTheSameUserAreCountedAsOneCandidate(): void
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
        // Planning window Jan->May, wide enough for two non-overlapping stints to both intersect it.
        $planning = $this->createPlanning($planningService, $creator, 'Seniors');
        $line = $lineRepository->findByPlanning($planning)[0];
        $team = $line->getPlanningTeam();
        $planningPeriod = $line->getPlanningPeriod();
        $this->activateRuleSet($ruleSetService, $team);

        $userX = $this->createUser($em);
        $firstStint = $this->addMember($membershipService, $team, $userX, TeamMemberRole::MEMBER, '2027-01-01', 1.0);
        $membershipService->endMembership($firstStint, $this->date('2027-02-01'));
        // Rejoins later, well within the same PlanningPeriod window.
        $this->addMember($membershipService, $team, $userX, TeamMemberRole::MEMBER, '2027-03-01', 1.0);

        $dutyType = $this->createDutyType($em, $team);
        // One duty covered by the first stint, one by the second.
        $this->createDuty($dutyMaterialization, $planningPeriod, $dutyType, '2027-01-15 08:00', '2027-01-15 20:00');
        $this->createDuty($dutyMaterialization, $planningPeriod, $dutyType, '2027-03-15 08:00', '2027-03-15 20:00');

        $context = $this->buildFairnessContext($em, $snapshotService, $matrixBuilder, $contextBuilder, $planningPeriod);

        $matchingCandidates = array_values(array_filter(
            $context->getCandidates(),
            static fn ($candidate) => $candidate->sourceUserStableId === (string) $userX->getStableId(),
        ));

        self::assertCount(1, $matchingCandidates, 'two stints of the same User in one snapshot must collapse into exactly one fairness candidate');
        self::assertCount(2, $matchingCandidates[0]->stints, 'but both stints must still be tracked, for date-scoped lookups');

        // Both duties (one per stint) must be counted for this one candidate — never silently dropped, never doubled.
        self::assertSame(2.0, $context->getEffectiveExposure((string) $userX->getStableId())->get(FairnessDimensionKey::totalDuties()));
    }
}
