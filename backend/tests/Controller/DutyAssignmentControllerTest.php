<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\FairnessPeriod;
use App\Entity\PlanningPeriod;
use App\Entity\TeamMemberRole;
use App\Repository\UserRepository;
use App\Service\DutyMaterializationService;
use App\Service\PlanningRuleSetService;
use App\Service\TeamMembershipService;
use App\Tests\AuthenticationTestHelpers;
use App\Tests\PlanningDomainTestHelpers;
use App\Tests\PlanningGenerationTestHelpers;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class DutyAssignmentControllerTest extends WebTestCase
{
    use AuthenticationTestHelpers;
    use PlanningDomainTestHelpers;
    use PlanningGenerationTestHelpers;

    private function authHeader(string $token): array
    {
        return ['HTTP_AUTHORIZATION' => 'Bearer '.$token, 'CONTENT_TYPE' => 'application/json'];
    }

    public function testAdminCanCreateManualAssignmentAndPlainMemberCannot(): void
    {
        $client = static::createClient();
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $userRepository = $container->get(UserRepository::class);
        $membershipService = $container->get(TeamMembershipService::class);
        $ruleSetService = $container->get(PlanningRuleSetService::class);
        $dutyMaterializationService = $container->get(DutyMaterializationService::class);

        $this->registerUser($client, 'da.admin@example.com', 'correct-horse-battery');
        $adminToken = $this->loginUser($client, 'da.admin@example.com', 'correct-horse-battery');
        $this->registerUser($client, 'da.member@example.com', 'correct-horse-battery');
        $memberToken = $this->loginUser($client, 'da.member@example.com', 'correct-horse-battery');

        $team = $this->createTeam($em);
        $planningPeriod = $this->createPlanningPeriod($em, $team, '2027-01-01', '2027-05-01');
        $this->activateRuleSet($ruleSetService, $team);
        $admin = $this->addMember($membershipService, $team, $userRepository->findOneByEmail('da.admin@example.com'), TeamMemberRole::ADMIN);
        $this->addMember($membershipService, $team, $userRepository->findOneByEmail('da.member@example.com'), TeamMemberRole::MEMBER);
        $dutyType = $this->createDutyType($em, $team);
        $duty = $this->createDuty($dutyMaterializationService, $planningPeriod, $dutyType, '2027-02-01 08:00', '2027-02-01 20:00');

        $createGenerationUrl = \sprintf('/api/planning-periods/%s/generations', $planningPeriod->getStableId());
        $client->request('POST', $createGenerationUrl, server: $this->authHeader($adminToken));
        $generationStableId = json_decode((string) $client->getResponse()->getContent(), true)['stableId'];
        $client->request('POST', \sprintf('/api/planning-generations/%s/snapshot', $generationStableId), server: $this->authHeader($adminToken));
        self::assertResponseStatusCodeSame(201);

        $assignUrl = \sprintf('/api/planning-generations/%s/assignments', $generationStableId);
        $payload = json_encode(['dutyStableId' => (string) $duty->getStableId(), 'teamMemberStableId' => (string) $admin->getStableId()]);

        $client->request('POST', $assignUrl, server: $this->authHeader($memberToken), content: $payload);
        self::assertResponseStatusCodeSame(403, 'A plain member must not be able to create a manual assignment.');

        $client->request('POST', $assignUrl, server: $this->authHeader($adminToken), content: $payload);
        self::assertResponseStatusCodeSame(201);
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('MANUAL', $data['source'], 'source must always be forced to MANUAL, never accepted from the client');
        self::assertFalse($data['locked']);
        self::assertSame((string) $duty->getStableId(), $data['dutyStableId']);
        self::assertSame((string) $admin->getStableId(), $data['teamMemberStableId']);
    }

    public function testCannotAssignBeforeGenerationIsSnapshotted(): void
    {
        $client = static::createClient();
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $userRepository = $container->get(UserRepository::class);
        $membershipService = $container->get(TeamMembershipService::class);
        $ruleSetService = $container->get(PlanningRuleSetService::class);
        $dutyMaterializationService = $container->get(DutyMaterializationService::class);

        $this->registerUser($client, 'da.nosnapshot@example.com', 'correct-horse-battery');
        $token = $this->loginUser($client, 'da.nosnapshot@example.com', 'correct-horse-battery');

        $team = $this->createTeam($em);
        $planningPeriod = $this->createPlanningPeriod($em, $team, '2027-01-01', '2027-05-01');
        $this->activateRuleSet($ruleSetService, $team);
        $member = $this->addMember($membershipService, $team, $userRepository->findOneByEmail('da.nosnapshot@example.com'), TeamMemberRole::OWNER);
        $dutyType = $this->createDutyType($em, $team);
        $duty = $this->createDuty($dutyMaterializationService, $planningPeriod, $dutyType, '2027-02-01 08:00', '2027-02-01 20:00');

        $client->request('POST', \sprintf('/api/planning-periods/%s/generations', $planningPeriod->getStableId()), server: $this->authHeader($token));
        $generationStableId = json_decode((string) $client->getResponse()->getContent(), true)['stableId'];

        $client->request(
            'POST',
            \sprintf('/api/planning-generations/%s/assignments', $generationStableId),
            server: $this->authHeader($token),
            content: json_encode(['dutyStableId' => (string) $duty->getStableId(), 'teamMemberStableId' => (string) $member->getStableId()]),
        );

        self::assertResponseStatusCodeSame(409);
        self::assertSame('generation_not_snapshotted', json_decode((string) $client->getResponse()->getContent(), true)['error']);
    }

    public function testDutyFromAnotherPlanningPeriodIsRejected(): void
    {
        $client = static::createClient();
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $userRepository = $container->get(UserRepository::class);
        $membershipService = $container->get(TeamMembershipService::class);
        $ruleSetService = $container->get(PlanningRuleSetService::class);
        $dutyMaterializationService = $container->get(DutyMaterializationService::class);

        $this->registerUser($client, 'da.wrongperiod@example.com', 'correct-horse-battery');
        $token = $this->loginUser($client, 'da.wrongperiod@example.com', 'correct-horse-battery');

        $team = $this->createTeam($em);
        // Two PlanningPeriods of the *same* Team sharing one FairnessPeriod
        // — createPlanningPeriod() always makes its own FairnessPeriod, and
        // a Team's FairnessPeriods may never overlap (D049), so it cannot
        // be reused here.
        $fairnessPeriod = new FairnessPeriod($team, '2027', $this->date('2027-01-01'), $this->date('2028-01-01'));
        $em->persist($fairnessPeriod);
        $planningPeriodA = new PlanningPeriod($team, $fairnessPeriod, 'Q1', $this->date('2027-01-01'), $this->date('2027-05-01'));
        $planningPeriodB = new PlanningPeriod($team, $fairnessPeriod, 'Q3', $this->date('2027-06-01'), $this->date('2027-09-01'));
        $em->persist($planningPeriodA);
        $em->persist($planningPeriodB);
        $em->flush();
        $this->activateRuleSet($ruleSetService, $team);
        $member = $this->addMember($membershipService, $team, $userRepository->findOneByEmail('da.wrongperiod@example.com'), TeamMemberRole::OWNER);
        $dutyType = $this->createDutyType($em, $team);
        $dutyFromB = $this->createDuty($dutyMaterializationService, $planningPeriodB, $dutyType, '2027-07-01 08:00', '2027-07-01 20:00');

        $client->request('POST', \sprintf('/api/planning-periods/%s/generations', $planningPeriodA->getStableId()), server: $this->authHeader($token));
        $generationStableId = json_decode((string) $client->getResponse()->getContent(), true)['stableId'];
        $client->request('POST', \sprintf('/api/planning-generations/%s/snapshot', $generationStableId), server: $this->authHeader($token));
        self::assertResponseStatusCodeSame(201);

        $client->request(
            'POST',
            \sprintf('/api/planning-generations/%s/assignments', $generationStableId),
            server: $this->authHeader($token),
            content: json_encode(['dutyStableId' => (string) $dutyFromB->getStableId(), 'teamMemberStableId' => (string) $member->getStableId()]),
        );

        self::assertResponseStatusCodeSame(422);
        self::assertSame('invalid_assignment', json_decode((string) $client->getResponse()->getContent(), true)['error']);
    }

    public function testTeamMemberFromAnotherTeamIsRejected(): void
    {
        $client = static::createClient();
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $userRepository = $container->get(UserRepository::class);
        $membershipService = $container->get(TeamMembershipService::class);
        $ruleSetService = $container->get(PlanningRuleSetService::class);
        $dutyMaterializationService = $container->get(DutyMaterializationService::class);

        $this->registerUser($client, 'da.wrongteam@example.com', 'correct-horse-battery');
        $token = $this->loginUser($client, 'da.wrongteam@example.com', 'correct-horse-battery');

        $teamA = $this->createTeam($em, 'Cardiology');
        $teamB = $this->createTeam($em, 'Radiology');
        $planningPeriodA = $this->createPlanningPeriod($em, $teamA, '2027-01-01', '2027-05-01');
        $this->activateRuleSet($ruleSetService, $teamA);
        $this->addMember($membershipService, $teamA, $userRepository->findOneByEmail('da.wrongteam@example.com'), TeamMemberRole::OWNER);
        $otherUser = $this->createUser($em);
        $memberOfB = $this->addMember($membershipService, $teamB, $otherUser, TeamMemberRole::MEMBER);
        $dutyType = $this->createDutyType($em, $teamA);
        $duty = $this->createDuty($dutyMaterializationService, $planningPeriodA, $dutyType, '2027-02-01 08:00', '2027-02-01 20:00');

        $client->request('POST', \sprintf('/api/planning-periods/%s/generations', $planningPeriodA->getStableId()), server: $this->authHeader($token));
        $generationStableId = json_decode((string) $client->getResponse()->getContent(), true)['stableId'];
        $client->request('POST', \sprintf('/api/planning-generations/%s/snapshot', $generationStableId), server: $this->authHeader($token));
        self::assertResponseStatusCodeSame(201);

        $client->request(
            'POST',
            \sprintf('/api/planning-generations/%s/assignments', $generationStableId),
            server: $this->authHeader($token),
            content: json_encode(['dutyStableId' => (string) $duty->getStableId(), 'teamMemberStableId' => (string) $memberOfB->getStableId()]),
        );

        self::assertResponseStatusCodeSame(422);
    }

    public function testDuplicateAssignmentForSameDutyAndGenerationIsRejected(): void
    {
        $client = static::createClient();
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $userRepository = $container->get(UserRepository::class);
        $membershipService = $container->get(TeamMembershipService::class);
        $ruleSetService = $container->get(PlanningRuleSetService::class);
        $dutyMaterializationService = $container->get(DutyMaterializationService::class);

        $this->registerUser($client, 'da.duplicate@example.com', 'correct-horse-battery');
        $token = $this->loginUser($client, 'da.duplicate@example.com', 'correct-horse-battery');

        $team = $this->createTeam($em);
        $planningPeriod = $this->createPlanningPeriod($em, $team, '2027-01-01', '2027-05-01');
        $this->activateRuleSet($ruleSetService, $team);
        $member = $this->addMember($membershipService, $team, $userRepository->findOneByEmail('da.duplicate@example.com'), TeamMemberRole::OWNER);
        $dutyType = $this->createDutyType($em, $team);
        $duty = $this->createDuty($dutyMaterializationService, $planningPeriod, $dutyType, '2027-02-01 08:00', '2027-02-01 20:00');

        $client->request('POST', \sprintf('/api/planning-periods/%s/generations', $planningPeriod->getStableId()), server: $this->authHeader($token));
        $generationStableId = json_decode((string) $client->getResponse()->getContent(), true)['stableId'];
        $client->request('POST', \sprintf('/api/planning-generations/%s/snapshot', $generationStableId), server: $this->authHeader($token));
        self::assertResponseStatusCodeSame(201);

        $assignUrl = \sprintf('/api/planning-generations/%s/assignments', $generationStableId);
        $payload = json_encode(['dutyStableId' => (string) $duty->getStableId(), 'teamMemberStableId' => (string) $member->getStableId()]);

        $client->request('POST', $assignUrl, server: $this->authHeader($token), content: $payload);
        self::assertResponseStatusCodeSame(201);

        $client->request('POST', $assignUrl, server: $this->authHeader($token), content: $payload);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('duplicate_assignment', json_decode((string) $client->getResponse()->getContent(), true)['error']);
    }

    public function testSameDutyCanBeAssignedInTwoDifferentGenerations(): void
    {
        $client = static::createClient();
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $userRepository = $container->get(UserRepository::class);
        $membershipService = $container->get(TeamMembershipService::class);
        $ruleSetService = $container->get(PlanningRuleSetService::class);
        $dutyMaterializationService = $container->get(DutyMaterializationService::class);

        $this->registerUser($client, 'da.tworuns@example.com', 'correct-horse-battery');
        $token = $this->loginUser($client, 'da.tworuns@example.com', 'correct-horse-battery');

        $team = $this->createTeam($em);
        $planningPeriod = $this->createPlanningPeriod($em, $team, '2027-01-01', '2027-05-01');
        $this->activateRuleSet($ruleSetService, $team);
        $member = $this->addMember($membershipService, $team, $userRepository->findOneByEmail('da.tworuns@example.com'), TeamMemberRole::OWNER);
        $dutyType = $this->createDutyType($em, $team);
        $duty = $this->createDuty($dutyMaterializationService, $planningPeriod, $dutyType, '2027-02-01 08:00', '2027-02-01 20:00');

        $generationsUrl = \sprintf('/api/planning-periods/%s/generations', $planningPeriod->getStableId());
        $payload = json_encode(['dutyStableId' => (string) $duty->getStableId(), 'teamMemberStableId' => (string) $member->getStableId()]);

        $client->request('POST', $generationsUrl, server: $this->authHeader($token));
        $generation1 = json_decode((string) $client->getResponse()->getContent(), true)['stableId'];
        $client->request('POST', \sprintf('/api/planning-generations/%s/snapshot', $generation1), server: $this->authHeader($token));
        $client->request('POST', \sprintf('/api/planning-generations/%s/assignments', $generation1), server: $this->authHeader($token), content: $payload);
        self::assertResponseStatusCodeSame(201);

        $client->request('POST', $generationsUrl, server: $this->authHeader($token));
        $generation2 = json_decode((string) $client->getResponse()->getContent(), true)['stableId'];
        $client->request('POST', \sprintf('/api/planning-generations/%s/snapshot', $generation2), server: $this->authHeader($token));
        $client->request('POST', \sprintf('/api/planning-generations/%s/assignments', $generation2), server: $this->authHeader($token), content: $payload);
        self::assertResponseStatusCodeSame(201, 'the same Duty must be assignable again in a second, independent generation');
    }

    public function testUnknownDutyReturns404(): void
    {
        $client = static::createClient();
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $userRepository = $container->get(UserRepository::class);
        $membershipService = $container->get(TeamMembershipService::class);
        $ruleSetService = $container->get(PlanningRuleSetService::class);

        $this->registerUser($client, 'da.unknownduty@example.com', 'correct-horse-battery');
        $token = $this->loginUser($client, 'da.unknownduty@example.com', 'correct-horse-battery');

        $team = $this->createTeam($em);
        $planningPeriod = $this->createPlanningPeriod($em, $team, '2027-01-01', '2027-05-01');
        $this->activateRuleSet($ruleSetService, $team);
        $member = $this->addMember($membershipService, $team, $userRepository->findOneByEmail('da.unknownduty@example.com'), TeamMemberRole::OWNER);

        $client->request('POST', \sprintf('/api/planning-periods/%s/generations', $planningPeriod->getStableId()), server: $this->authHeader($token));
        $generationStableId = json_decode((string) $client->getResponse()->getContent(), true)['stableId'];
        $client->request('POST', \sprintf('/api/planning-generations/%s/snapshot', $generationStableId), server: $this->authHeader($token));

        $client->request(
            'POST',
            \sprintf('/api/planning-generations/%s/assignments', $generationStableId),
            server: $this->authHeader($token),
            content: json_encode(['dutyStableId' => '00000000-0000-7000-8000-000000000000', 'teamMemberStableId' => (string) $member->getStableId()]),
        );

        self::assertResponseStatusCodeSame(404);
    }

    public function testWithoutTokenReturns401(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $team = $this->createTeam($em);
        $planningPeriod = $this->createPlanningPeriod($em, $team);

        $client->request('POST', \sprintf('/api/planning-periods/%s/generations', $planningPeriod->getStableId()));
        self::assertResponseStatusCodeSame(401);
    }
}
