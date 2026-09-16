<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\TeamMemberRole;
use App\Repository\UserRepository;
use App\Service\PlanningRuleSetService;
use App\Service\TeamMembershipService;
use App\Tests\AuthenticationTestHelpers;
use App\Tests\PlanningDomainTestHelpers;
use App\Tests\PlanningGenerationTestHelpers;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PlanningGenerationControllerTest extends WebTestCase
{
    use AuthenticationTestHelpers;
    use PlanningDomainTestHelpers;
    use PlanningGenerationTestHelpers;

    private function authHeader(string $token): array
    {
        return ['HTTP_AUTHORIZATION' => 'Bearer '.$token, 'CONTENT_TYPE' => 'application/json'];
    }

    public function testAdminCanCreateGenerationAndPlainMemberCannot(): void
    {
        $client = static::createClient();
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $userRepository = $container->get(UserRepository::class);
        $membershipService = $container->get(TeamMembershipService::class);

        $this->registerUser($client, 'pg.admin@example.com', 'correct-horse-battery');
        $adminToken = $this->loginUser($client, 'pg.admin@example.com', 'correct-horse-battery');
        $this->registerUser($client, 'pg.member@example.com', 'correct-horse-battery');
        $memberToken = $this->loginUser($client, 'pg.member@example.com', 'correct-horse-battery');

        $team = $this->createTeam($em);
        $planningPeriod = $this->createPlanningPeriod($em, $team);
        $this->addMember($membershipService, $team, $userRepository->findOneByEmail('pg.admin@example.com'), TeamMemberRole::ADMIN);
        $this->addMember($membershipService, $team, $userRepository->findOneByEmail('pg.member@example.com'), TeamMemberRole::MEMBER);

        $url = \sprintf('/api/planning-periods/%s/generations', $planningPeriod->getStableId());

        $client->request('POST', $url, server: $this->authHeader($memberToken));
        self::assertResponseStatusCodeSame(403, 'A plain member must not be able to create a PlanningGeneration.');

        $client->request('POST', $url, server: $this->authHeader($adminToken));
        self::assertResponseStatusCodeSame(201);
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('DRAFT', $data['status']);
        self::assertSame((string) $planningPeriod->getStableId(), $data['planningPeriodStableId']);
    }

    public function testUnknownPlanningPeriodReturns404(): void
    {
        $client = static::createClient();
        $this->registerUser($client, 'pg.unknown@example.com', 'correct-horse-battery');
        $token = $this->loginUser($client, 'pg.unknown@example.com', 'correct-horse-battery');

        $client->request('POST', '/api/planning-periods/00000000-0000-7000-8000-000000000000/generations', server: $this->authHeader($token));

        self::assertResponseStatusCodeSame(404);
    }

    public function testListReturnsMostRecentGenerationFirst(): void
    {
        $client = static::createClient();
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $userRepository = $container->get(UserRepository::class);
        $membershipService = $container->get(TeamMembershipService::class);

        $this->registerUser($client, 'pg.list@example.com', 'correct-horse-battery');
        $token = $this->loginUser($client, 'pg.list@example.com', 'correct-horse-battery');

        $team = $this->createTeam($em);
        $planningPeriod = $this->createPlanningPeriod($em, $team);
        $this->addMember($membershipService, $team, $userRepository->findOneByEmail('pg.list@example.com'), TeamMemberRole::OWNER);

        $url = \sprintf('/api/planning-periods/%s/generations', $planningPeriod->getStableId());
        $client->request('POST', $url, server: $this->authHeader($token));
        $first = json_decode((string) $client->getResponse()->getContent(), true)['stableId'];
        $client->request('POST', $url, server: $this->authHeader($token));
        $second = json_decode((string) $client->getResponse()->getContent(), true)['stableId'];

        $client->request('GET', $url, server: $this->authHeader($token));
        self::assertResponseStatusCodeSame(200);
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertCount(2, $data);
        self::assertSame($second, $data[0]['stableId'], 'the most recently created generation must come first');
        self::assertSame($first, $data[1]['stableId']);
    }

    public function testSnapshotLifecycle(): void
    {
        $client = static::createClient();
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $userRepository = $container->get(UserRepository::class);
        $membershipService = $container->get(TeamMembershipService::class);
        $ruleSetService = $container->get(PlanningRuleSetService::class);

        $this->registerUser($client, 'pg.snapshot@example.com', 'correct-horse-battery');
        $adminToken = $this->loginUser($client, 'pg.snapshot@example.com', 'correct-horse-battery');

        $team = $this->createTeam($em);
        $planningPeriod = $this->createPlanningPeriod($em, $team, '2027-01-01', '2027-05-01');
        $this->activateRuleSet($ruleSetService, $team);
        $admin = $userRepository->findOneByEmail('pg.snapshot@example.com');
        $this->addMember($membershipService, $team, $admin, TeamMemberRole::OWNER, '2027-01-01', 1.0);

        $createUrl = \sprintf('/api/planning-periods/%s/generations', $planningPeriod->getStableId());
        $client->request('POST', $createUrl, server: $this->authHeader($adminToken));
        $generationStableId = json_decode((string) $client->getResponse()->getContent(), true)['stableId'];

        $snapshotUrl = \sprintf('/api/planning-generations/%s/snapshot', $generationStableId);

        // No snapshot yet.
        $client->request('GET', $snapshotUrl, server: $this->authHeader($adminToken));
        self::assertResponseStatusCodeSame(404);

        $client->request('POST', $snapshotUrl, server: $this->authHeader($adminToken));
        self::assertResponseStatusCodeSame(201);
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame(1, $data['summary']['memberCount']);
        self::assertSame('SNAPSHOTTED', $data['generation']['status']);
        self::assertNotNull($data['ruleSet']);

        // A second snapshot attempt on the same generation is a conflict.
        $client->request('POST', $snapshotUrl, server: $this->authHeader($adminToken));
        self::assertResponseStatusCodeSame(409);

        $client->request('GET', $snapshotUrl, server: $this->authHeader($adminToken));
        self::assertResponseStatusCodeSame(200);
        $readBack = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame($data['members'], $readBack['members']);
    }

    public function testSnapshotWithoutActiveRuleSetReturns409(): void
    {
        $client = static::createClient();
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $userRepository = $container->get(UserRepository::class);
        $membershipService = $container->get(TeamMembershipService::class);

        $this->registerUser($client, 'pg.norules@example.com', 'correct-horse-battery');
        $token = $this->loginUser($client, 'pg.norules@example.com', 'correct-horse-battery');

        $team = $this->createTeam($em);
        $planningPeriod = $this->createPlanningPeriod($em, $team);
        $this->addMember($membershipService, $team, $userRepository->findOneByEmail('pg.norules@example.com'), TeamMemberRole::OWNER);

        $createUrl = \sprintf('/api/planning-periods/%s/generations', $planningPeriod->getStableId());
        $client->request('POST', $createUrl, server: $this->authHeader($token));
        $generationStableId = json_decode((string) $client->getResponse()->getContent(), true)['stableId'];

        $client->request('POST', \sprintf('/api/planning-generations/%s/snapshot', $generationStableId), server: $this->authHeader($token));
        self::assertResponseStatusCodeSame(409);
    }

    public function testNonMemberCannotViewGenerations(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $this->registerUser($client, 'pg.outsider@example.com', 'correct-horse-battery');
        $token = $this->loginUser($client, 'pg.outsider@example.com', 'correct-horse-battery');

        $team = $this->createTeam($em);
        $planningPeriod = $this->createPlanningPeriod($em, $team);

        $client->request('GET', \sprintf('/api/planning-periods/%s/generations', $planningPeriod->getStableId()), server: $this->authHeader($token));
        self::assertResponseStatusCodeSame(403);
    }

    public function testWithoutTokenReturns401(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $team = $this->createTeam($em);
        $planningPeriod = $this->createPlanningPeriod($em, $team);

        $client->request('GET', \sprintf('/api/planning-periods/%s/generations', $planningPeriod->getStableId()));
        self::assertResponseStatusCodeSame(401);
    }
}
