<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\ParticipationFactorChangeReason;
use App\Entity\PlanningGeneration;
use App\Entity\TeamMemberRole;
use App\Entity\UserAvailabilityType;
use App\Exception\NoActivePlanningRuleSetException;
use App\Exception\PlanningGenerationAlreadySnapshottedException;
use App\Repository\PlanningGenerationRepository;
use App\Repository\PlanningSnapshotMemberRepository;
use App\Repository\PlanningSnapshotRepository;
use App\Repository\PlanningSnapshotRuleSetRepository;
use App\Service\ParticipationPeriodService;
use App\Service\PlanningRuleSetService;
use App\Service\PlanningSnapshotService;
use App\Service\PlanningTeamMembershipService;
use App\Service\TeamMemberNonParticipationService;
use App\Service\UserAvailabilityService;
use App\Tests\PlanningDomainTestHelpers;
use App\Tests\PlanningGenerationTestHelpers;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PlanningSnapshotServiceTest extends KernelTestCase
{
    use PlanningDomainTestHelpers;
    use PlanningGenerationTestHelpers;

    public function testSnapshotCapturesRelevantMemberAndParticipationFactor(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $snapshotService = self::getContainer()->get(PlanningSnapshotService::class);
        $membershipService = self::getContainer()->get(PlanningTeamMembershipService::class);
        $ruleSetService = self::getContainer()->get(PlanningRuleSetService::class);
        $memberRepository = self::getContainer()->get(PlanningSnapshotMemberRepository::class);

        $team = $this->createTeam($em);
        $planningPeriod = $this->createPlanningPeriod($em, $team, '2027-01-01', '2027-05-01');
        $this->activateRuleSet($ruleSetService, $team);
        $user = $this->createUser($em);
        $member = $this->addMember($membershipService, $team, $user, TeamMemberRole::MEMBER, '2027-01-01', 0.75);

        $generation = new PlanningGeneration($planningPeriod);
        $em->persist($generation);
        $em->flush();

        $snapshot = $snapshotService->createSnapshot($generation);

        self::assertCount(1, $snapshot->getMembers());
        $snapshotMember = $snapshot->getMembers()->first();
        self::assertTrue($snapshotMember->getSourceTeamMemberStableId()->equals($member->getStableId()));
        self::assertTrue($snapshotMember->getSourceUserStableId()->equals($user->getStableId()));
        self::assertSame(TeamMemberRole::MEMBER, $snapshotMember->getRole());
        self::assertCount(1, $snapshotMember->getParticipationPeriods());
        self::assertSame(0.75, $snapshotMember->getParticipationPeriods()->first()->toFloat());

        self::assertNotNull($memberRepository->findOneBySnapshotAndTeamMemberStableId($snapshot, $member->getStableId()));
    }

    public function testSnapshotCapturesUnavailableAndPreferDuty(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $snapshotService = self::getContainer()->get(PlanningSnapshotService::class);
        $membershipService = self::getContainer()->get(PlanningTeamMembershipService::class);
        $ruleSetService = self::getContainer()->get(PlanningRuleSetService::class);
        $availabilityService = self::getContainer()->get(UserAvailabilityService::class);

        $team = $this->createTeam($em);
        $planningPeriod = $this->createPlanningPeriod($em, $team, '2027-01-01', '2027-05-01');
        $this->activateRuleSet($ruleSetService, $team);
        $user = $this->createUser($em);
        $this->addMember($membershipService, $team, $user);

        $unavailable = $this->addAvailability($availabilityService, $user, UserAvailabilityType::UNAVAILABLE, '2027-02-01 00:00', '2027-02-10 00:00');
        $prefer = $this->addAvailability($availabilityService, $user, UserAvailabilityType::PREFER_DUTY, '2027-03-01 00:00', '2027-03-05 00:00');

        $generation = new PlanningGeneration($planningPeriod);
        $em->persist($generation);
        $em->flush();

        $snapshot = $snapshotService->createSnapshot($generation);

        $snapshotMember = $snapshot->getMembers()->first();
        self::assertCount(2, $snapshotMember->getAvailabilityPeriods());

        $types = array_map(static fn ($p) => $p->getType(), $snapshotMember->getAvailabilityPeriods()->toArray());
        self::assertContains(UserAvailabilityType::UNAVAILABLE, $types);
        self::assertContains(UserAvailabilityType::PREFER_DUTY, $types);

        $sourceIds = array_map(static fn ($p) => (string) $p->getSourceAvailabilityStableId(), $snapshotMember->getAvailabilityPeriods()->toArray());
        self::assertContains((string) $unavailable->getStableId(), $sourceIds);
        self::assertContains((string) $prefer->getStableId(), $sourceIds);
    }

    public function testSnapshotCapturesNonParticipation(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $snapshotService = self::getContainer()->get(PlanningSnapshotService::class);
        $membershipService = self::getContainer()->get(PlanningTeamMembershipService::class);
        $ruleSetService = self::getContainer()->get(PlanningRuleSetService::class);
        $nonParticipationService = self::getContainer()->get(TeamMemberNonParticipationService::class);

        $team = $this->createTeam($em);
        $planningPeriod = $this->createPlanningPeriod($em, $team, '2027-01-01', '2027-05-01');
        $this->activateRuleSet($ruleSetService, $team);
        $user = $this->createUser($em);
        $member = $this->addMember($membershipService, $team, $user);
        $nonParticipation = $this->addNonParticipation($nonParticipationService, $member, '2027-02-01 00:00', '2027-02-15 00:00');

        $generation = new PlanningGeneration($planningPeriod);
        $em->persist($generation);
        $em->flush();

        $snapshot = $snapshotService->createSnapshot($generation);

        $snapshotMember = $snapshot->getMembers()->first();
        self::assertCount(1, $snapshotMember->getNonParticipationPeriods());
        self::assertSame(
            (string) $nonParticipation->getStableId(),
            (string) $snapshotMember->getNonParticipationPeriods()->first()->getSourceNonParticipationStableId(),
        );
    }

    public function testSnapshotCapturesActiveRuleSetConfiguration(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $snapshotService = self::getContainer()->get(PlanningSnapshotService::class);
        $ruleSetService = self::getContainer()->get(PlanningRuleSetService::class);
        $ruleSetRepository = self::getContainer()->get(PlanningSnapshotRuleSetRepository::class);

        $team = $this->createTeam($em);
        $planningPeriod = $this->createPlanningPeriod($em, $team, '2027-01-01', '2027-05-01');
        $ruleSet = $this->activateRuleSet($ruleSetService, $team);

        $generation = new PlanningGeneration($planningPeriod);
        $em->persist($generation);
        $em->flush();

        $snapshot = $snapshotService->createSnapshot($generation);
        $snapshotRuleSet = $ruleSetRepository->findOneBySnapshot($snapshot);

        self::assertNotNull($snapshotRuleSet);
        self::assertTrue($snapshotRuleSet->getSourcePlanningRuleSetStableId()->equals($ruleSet->getStableId()));
        self::assertSame($ruleSet->getVersion(), $snapshotRuleSet->getSourceVersion());
        self::assertSame($ruleSet->getConfiguration(), $snapshotRuleSet->getConfiguration());
    }

    public function testCannotSnapshotWithoutAnActiveRuleSet(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $snapshotService = self::getContainer()->get(PlanningSnapshotService::class);

        $team = $this->createTeam($em);
        $planningPeriod = $this->createPlanningPeriod($em, $team);

        $generation = new PlanningGeneration($planningPeriod);
        $em->persist($generation);
        $em->flush();

        $this->expectException(NoActivePlanningRuleSetException::class);
        $snapshotService->createSnapshot($generation);
    }

    public function testOnlyMembersIntersectingThePlanningPeriodAreCaptured(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $snapshotService = self::getContainer()->get(PlanningSnapshotService::class);
        $membershipService = self::getContainer()->get(PlanningTeamMembershipService::class);
        $ruleSetService = self::getContainer()->get(PlanningRuleSetService::class);

        $team = $this->createTeam($em);
        $planningPeriod = $this->createPlanningPeriod($em, $team, '2027-05-01', '2027-09-01');
        $this->activateRuleSet($ruleSetService, $team);

        $irrelevantUser = $this->createUser($em);
        $irrelevantMember = $this->addMember($membershipService, $team, $irrelevantUser, TeamMemberRole::MEMBER, '2026-01-01');
        $membershipService->endMembership($irrelevantMember, $this->date('2027-01-01'));

        $relevantUser = $this->createUser($em);
        $this->addMember($membershipService, $team, $relevantUser, TeamMemberRole::MEMBER, '2027-06-01');

        $generation = new PlanningGeneration($planningPeriod);
        $em->persist($generation);
        $em->flush();

        $snapshot = $snapshotService->createSnapshot($generation);

        self::assertCount(1, $snapshot->getMembers());
        self::assertTrue($snapshot->getMembers()->first()->getSourceUserStableId()->equals($relevantUser->getStableId()));
    }

    public function testMultiTeamDataIsIsolated(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $snapshotService = self::getContainer()->get(PlanningSnapshotService::class);
        $membershipService = self::getContainer()->get(PlanningTeamMembershipService::class);
        $ruleSetService = self::getContainer()->get(PlanningRuleSetService::class);

        $teamA = $this->createTeam($em, 'Cardiology');
        $teamB = $this->createTeam($em, 'Radiology');
        $planningPeriodA = $this->createPlanningPeriod($em, $teamA);
        $this->createPlanningPeriod($em, $teamB);
        $this->activateRuleSet($ruleSetService, $teamA);
        $this->activateRuleSet($ruleSetService, $teamB);

        $userA = $this->createUser($em);
        $this->addMember($membershipService, $teamA, $userA);
        $userB = $this->createUser($em);
        $this->addMember($membershipService, $teamB, $userB);

        $generationA = new PlanningGeneration($planningPeriodA);
        $em->persist($generationA);
        $em->flush();

        $snapshotA = $snapshotService->createSnapshot($generationA);

        self::assertCount(1, $snapshotA->getMembers());
        self::assertTrue($snapshotA->getMembers()->first()->getSourceUserStableId()->equals($userA->getStableId()));
    }

    public function testSnapshotIsImmutableAfterLaterMutations(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $snapshotService = self::getContainer()->get(PlanningSnapshotService::class);
        $membershipService = self::getContainer()->get(PlanningTeamMembershipService::class);
        $ruleSetService = self::getContainer()->get(PlanningRuleSetService::class);
        $availabilityService = self::getContainer()->get(UserAvailabilityService::class);
        $participationPeriodService = self::getContainer()->get(ParticipationPeriodService::class);
        $nonParticipationService = self::getContainer()->get(TeamMemberNonParticipationService::class);

        $team = $this->createTeam($em);
        $planningPeriod = $this->createPlanningPeriod($em, $team, '2027-01-01', '2027-05-01');
        $this->activateRuleSet($ruleSetService, $team);
        $user = $this->createUser($em);
        $member = $this->addMember($membershipService, $team, $user, TeamMemberRole::MEMBER, '2027-01-01', 1.0);
        $availability = $this->addAvailability($availabilityService, $user, UserAvailabilityType::UNAVAILABLE, '2027-02-01 00:00', '2027-02-10 00:00');

        $generation = new PlanningGeneration($planningPeriod);
        $em->persist($generation);
        $em->flush();

        $snapshot = $snapshotService->createSnapshot($generation);
        $snapshotStableId = $snapshot->getGeneration()->getStableId();

        // Mutate every piece of live state the snapshot captured.
        $participationPeriodService->changeFactor(
            $member,
            $this->date('2027-03-01'),
            0.5,
            ParticipationFactorChangeReason::CONTRACTUAL_CHANGE,
        );
        $availabilityService->reschedule(
            $availability,
            UserAvailabilityType::PREFER_DUTY,
            $this->date('2027-02-20 00:00'),
            $this->date('2027-02-25 00:00'),
        );
        $nonParticipationService->create(
            $member,
            $this->date('2027-04-01 00:00'),
            $this->date('2027-04-05 00:00'),
        );

        // Detach everything and re-read from the database — never trust
        // in-memory PHP objects for an immutability assertion.
        $em->clear();

        $snapshotRepository = self::getContainer()->get(PlanningSnapshotRepository::class);
        $generationRepository = self::getContainer()->get(PlanningGenerationRepository::class);
        $reloadedGeneration = $generationRepository->findOneByStableId((string) $snapshotStableId);
        $reloadedSnapshot = $snapshotRepository->findOneByGeneration($reloadedGeneration);

        self::assertCount(1, $reloadedSnapshot->getMembers());
        $reloadedMember = $reloadedSnapshot->getMembers()->first();

        self::assertCount(1, $reloadedMember->getParticipationPeriods(), 'the snapshot must still show only the single period valid at capture time');
        self::assertSame(1.0, $reloadedMember->getParticipationPeriods()->first()->toFloat(), 'participationFactor must stay frozen at 1.0, not the later 0.5');

        self::assertCount(1, $reloadedMember->getAvailabilityPeriods(), 'the reschedule must not appear as a second period');
        self::assertSame(UserAvailabilityType::UNAVAILABLE, $reloadedMember->getAvailabilityPeriods()->first()->getType(), 'must stay UNAVAILABLE, not the later PREFER_DUTY');

        self::assertCount(0, $reloadedMember->getNonParticipationPeriods(), 'a non-participation period created after the snapshot must never appear in it');
    }

    public function testTwoGenerationsProduceIndependentSnapshots(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $snapshotService = self::getContainer()->get(PlanningSnapshotService::class);
        $membershipService = self::getContainer()->get(PlanningTeamMembershipService::class);
        $ruleSetService = self::getContainer()->get(PlanningRuleSetService::class);
        $participationPeriodService = self::getContainer()->get(ParticipationPeriodService::class);

        $team = $this->createTeam($em);
        $planningPeriod = $this->createPlanningPeriod($em, $team, '2027-01-01', '2027-05-01');
        $this->activateRuleSet($ruleSetService, $team);
        $user = $this->createUser($em);
        $member = $this->addMember($membershipService, $team, $user, TeamMemberRole::MEMBER, '2027-01-01', 1.0);

        $generation1 = new PlanningGeneration($planningPeriod);
        $em->persist($generation1);
        $em->flush();
        $snapshot1 = $snapshotService->createSnapshot($generation1);

        $participationPeriodService->changeFactor(
            $member,
            $this->date('2027-02-01'),
            0.5,
            ParticipationFactorChangeReason::CONTRACTUAL_CHANGE,
        );

        $generation2 = new PlanningGeneration($planningPeriod);
        $em->persist($generation2);
        $em->flush();
        $snapshot2 = $snapshotService->createSnapshot($generation2);

        self::assertNotSame($snapshot1->getId(), $snapshot2->getId());
        self::assertCount(1, $snapshot1->getMembers()->first()->getParticipationPeriods(), 'snapshot1 must still show only the initial segment');
        self::assertCount(2, $snapshot2->getMembers()->first()->getParticipationPeriods(), 'snapshot2 must reflect the new segment created after snapshot1');
    }

    public function testCannotSnapshotTheSameGenerationTwice(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $snapshotService = self::getContainer()->get(PlanningSnapshotService::class);
        $ruleSetService = self::getContainer()->get(PlanningRuleSetService::class);

        $team = $this->createTeam($em);
        $planningPeriod = $this->createPlanningPeriod($em, $team);
        $this->activateRuleSet($ruleSetService, $team);

        $generation = new PlanningGeneration($planningPeriod);
        $em->persist($generation);
        $em->flush();

        $snapshotService->createSnapshot($generation);

        $this->expectException(PlanningGenerationAlreadySnapshottedException::class);
        $snapshotService->createSnapshot($generation);
    }
}
