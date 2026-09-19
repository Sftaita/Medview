<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\TeamMemberRole;
use App\Repository\PlanningLineRepository;
use App\Repository\UserRepository;
use App\Service\PlanningRuleSetService;
use App\Service\PlanningService;
use App\Service\PlanningTeamMembershipService;
use App\Tests\AuthenticationTestHelpers;
use App\Tests\PlanningDomainTestHelpers;
use App\Tests\PlanningGenerationTestHelpers;
use App\Tests\PlanningTestHelpers;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PlanningGenerationControllerTest extends WebTestCase
{
    use AuthenticationTestHelpers;
    use PlanningDomainTestHelpers;
    use PlanningGenerationTestHelpers;
    use PlanningTestHelpers;

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
        $membershipService = $container->get(PlanningTeamMembershipService::class);

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
        $membershipService = $container->get(PlanningTeamMembershipService::class);

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
        $membershipService = $container->get(PlanningTeamMembershipService::class);
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
        $membershipService = $container->get(PlanningTeamMembershipService::class);

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

    /**
     * docs/decisions.md D105 — an empty body preserves the exact
     * pre-Lot-6D.1 behavior: both rest policies disabled.
     */
    public function testCreateWithoutBodyDisablesBothRestPolicies(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $userRepository = static::getContainer()->get(UserRepository::class);
        $membershipService = static::getContainer()->get(PlanningTeamMembershipService::class);

        $this->registerUser($client, 'pg.restnone@example.com', 'correct-horse-battery');
        $token = $this->loginUser($client, 'pg.restnone@example.com', 'correct-horse-battery');

        $team = $this->createTeam($em);
        $planningPeriod = $this->createPlanningPeriod($em, $team);
        $this->addMember($membershipService, $team, $userRepository->findOneByEmail('pg.restnone@example.com'), TeamMemberRole::OWNER);

        $client->request('POST', \sprintf('/api/planning-periods/%s/generations', $planningPeriod->getStableId()), server: $this->authHeader($token));

        self::assertResponseStatusCodeSame(201);
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertFalse($data['restPolicy']['legalMinRest']['enabled']);
        self::assertNull($data['restPolicy']['legalMinRest']['hours']);
        self::assertFalse($data['restPolicy']['teamMinRest']['enabled']);
        self::assertNull($data['restPolicy']['teamMinRest']['hours']);
    }

    public function testCreateWithValidRestPolicyBodyPersistsBothPolicies(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $userRepository = static::getContainer()->get(UserRepository::class);
        $membershipService = static::getContainer()->get(PlanningTeamMembershipService::class);

        $this->registerUser($client, 'pg.restvalid@example.com', 'correct-horse-battery');
        $token = $this->loginUser($client, 'pg.restvalid@example.com', 'correct-horse-battery');

        $team = $this->createTeam($em);
        $planningPeriod = $this->createPlanningPeriod($em, $team);
        $this->addMember($membershipService, $team, $userRepository->findOneByEmail('pg.restvalid@example.com'), TeamMemberRole::OWNER);

        $body = json_encode([
            'legalMinRestEnabled' => true,
            'legalMinRestHours' => 11,
            'teamMinRestEnabled' => true,
            'teamMinRestHours' => 14,
        ]);
        $client->request('POST', \sprintf('/api/planning-periods/%s/generations', $planningPeriod->getStableId()), server: $this->authHeader($token), content: $body);

        self::assertResponseStatusCodeSame(201);
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertTrue($data['restPolicy']['legalMinRest']['enabled']);
        self::assertSame(11, $data['restPolicy']['legalMinRest']['hours']);
        self::assertTrue($data['restPolicy']['teamMinRest']['enabled']);
        self::assertSame(14, $data['restPolicy']['teamMinRest']['hours']);

        // Re-reading the generation later confirms the frozen values persist.
        $client->request('GET', \sprintf('/api/planning-generations/%s', $data['stableId']), server: $this->authHeader($token));
        self::assertResponseStatusCodeSame(200);
        $readBack = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame(11, $readBack['restPolicy']['legalMinRest']['hours']);
        self::assertSame(14, $readBack['restPolicy']['teamMinRest']['hours']);
    }

    public function testCreateWithLegalEnabledButNoHoursReturns422(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $userRepository = static::getContainer()->get(UserRepository::class);
        $membershipService = static::getContainer()->get(PlanningTeamMembershipService::class);

        $this->registerUser($client, 'pg.restmissinghours@example.com', 'correct-horse-battery');
        $token = $this->loginUser($client, 'pg.restmissinghours@example.com', 'correct-horse-battery');

        $team = $this->createTeam($em);
        $planningPeriod = $this->createPlanningPeriod($em, $team);
        $this->addMember($membershipService, $team, $userRepository->findOneByEmail('pg.restmissinghours@example.com'), TeamMemberRole::OWNER);

        $body = json_encode(['legalMinRestEnabled' => true]);
        $client->request('POST', \sprintf('/api/planning-periods/%s/generations', $planningPeriod->getStableId()), server: $this->authHeader($token), content: $body);

        self::assertResponseStatusCodeSame(422);
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertArrayHasKey('legalMinRestHours', $data['violations']);
    }

    public function testCreateWithHoursButPolicyDisabledReturns422(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $userRepository = static::getContainer()->get(UserRepository::class);
        $membershipService = static::getContainer()->get(PlanningTeamMembershipService::class);

        $this->registerUser($client, 'pg.restneverignored@example.com', 'correct-horse-battery');
        $token = $this->loginUser($client, 'pg.restneverignored@example.com', 'correct-horse-battery');

        $team = $this->createTeam($em);
        $planningPeriod = $this->createPlanningPeriod($em, $team);
        $this->addMember($membershipService, $team, $userRepository->findOneByEmail('pg.restneverignored@example.com'), TeamMemberRole::OWNER);

        $body = json_encode(['legalMinRestHours' => 11]);
        $client->request('POST', \sprintf('/api/planning-periods/%s/generations', $planningPeriod->getStableId()), server: $this->authHeader($token), content: $body);

        self::assertResponseStatusCodeSame(422, 'An hours value must never be silently ignored when its policy is disabled.');
    }

    public function testCreateWithTeamHoursBelowLegalHoursReturns422(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $userRepository = static::getContainer()->get(UserRepository::class);
        $membershipService = static::getContainer()->get(PlanningTeamMembershipService::class);

        $this->registerUser($client, 'pg.restteamtoolow@example.com', 'correct-horse-battery');
        $token = $this->loginUser($client, 'pg.restteamtoolow@example.com', 'correct-horse-battery');

        $team = $this->createTeam($em);
        $planningPeriod = $this->createPlanningPeriod($em, $team);
        $this->addMember($membershipService, $team, $userRepository->findOneByEmail('pg.restteamtoolow@example.com'), TeamMemberRole::OWNER);

        $body = json_encode([
            'legalMinRestEnabled' => true,
            'legalMinRestHours' => 11,
            'teamMinRestEnabled' => true,
            'teamMinRestHours' => 8,
        ]);
        $client->request('POST', \sprintf('/api/planning-periods/%s/generations', $planningPeriod->getStableId()), server: $this->authHeader($token), content: $body);

        self::assertResponseStatusCodeSame(422);
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertArrayHasKey('teamMinRestHours', $data['violations']);
    }

    public function testCreateWithMalformedJsonBodyReturns400(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $userRepository = static::getContainer()->get(UserRepository::class);
        $membershipService = static::getContainer()->get(PlanningTeamMembershipService::class);

        $this->registerUser($client, 'pg.restbadjson@example.com', 'correct-horse-battery');
        $token = $this->loginUser($client, 'pg.restbadjson@example.com', 'correct-horse-battery');

        $team = $this->createTeam($em);
        $planningPeriod = $this->createPlanningPeriod($em, $team);
        $this->addMember($membershipService, $team, $userRepository->findOneByEmail('pg.restbadjson@example.com'), TeamMemberRole::OWNER);

        $client->request('POST', \sprintf('/api/planning-periods/%s/generations', $planningPeriod->getStableId()), server: $this->authHeader($token), content: '{not valid json');

        self::assertResponseStatusCodeSame(400);
    }

    /**
     * docs/decisions.md D105 — options are frozen per generation: creating a
     * second generation with different rest-policy values must never alter
     * the first one already persisted.
     */
    public function testDifferentGenerationsOfTheSamePlanningPeriodCanUseDifferentRestPolicies(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $userRepository = static::getContainer()->get(UserRepository::class);
        $membershipService = static::getContainer()->get(PlanningTeamMembershipService::class);

        $this->registerUser($client, 'pg.resthistory@example.com', 'correct-horse-battery');
        $token = $this->loginUser($client, 'pg.resthistory@example.com', 'correct-horse-battery');

        $team = $this->createTeam($em);
        $planningPeriod = $this->createPlanningPeriod($em, $team);
        $this->addMember($membershipService, $team, $userRepository->findOneByEmail('pg.resthistory@example.com'), TeamMemberRole::OWNER);

        $url = \sprintf('/api/planning-periods/%s/generations', $planningPeriod->getStableId());

        $client->request('POST', $url, server: $this->authHeader($token), content: json_encode(['legalMinRestEnabled' => true, 'legalMinRestHours' => 11]));
        self::assertResponseStatusCodeSame(201);
        $first = json_decode((string) $client->getResponse()->getContent(), true);

        $client->request('POST', $url, server: $this->authHeader($token), content: json_encode(['teamMinRestEnabled' => true, 'teamMinRestHours' => 9]));
        self::assertResponseStatusCodeSame(201);
        $second = json_decode((string) $client->getResponse()->getContent(), true);

        // Re-read the first generation: it must still show its own frozen values, unaffected by the second creation.
        $client->request('GET', \sprintf('/api/planning-generations/%s', $first['stableId']), server: $this->authHeader($token));
        $firstReadBack = json_decode((string) $client->getResponse()->getContent(), true);

        self::assertTrue($firstReadBack['restPolicy']['legalMinRest']['enabled']);
        self::assertSame(11, $firstReadBack['restPolicy']['legalMinRest']['hours']);
        self::assertFalse($firstReadBack['restPolicy']['teamMinRest']['enabled']);

        self::assertFalse($second['restPolicy']['legalMinRest']['enabled']);
        self::assertTrue($second['restPolicy']['teamMinRest']['enabled']);
        self::assertSame(9, $second['restPolicy']['teamMinRest']['hours']);
    }

    // --- POST /api/planning-generations/{stableId}/solve (docs/decisions.md D106) ---

    public function testAdminCanSolveAndPlainMemberCannot(): void
    {
        $client = static::createClient();
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $userRepository = $container->get(UserRepository::class);
        $membershipService = $container->get(PlanningTeamMembershipService::class);
        $ruleSetService = $container->get(PlanningRuleSetService::class);
        $planningService = $container->get(PlanningService::class);
        $lineRepository = $container->get(PlanningLineRepository::class);

        $this->registerUser($client, 'solve.admin@example.com', 'correct-horse-battery');
        $adminToken = $this->loginUser($client, 'solve.admin@example.com', 'correct-horse-battery');
        $this->registerUser($client, 'solve.member@example.com', 'correct-horse-battery');
        $memberToken = $this->loginUser($client, 'solve.member@example.com', 'correct-horse-battery');

        $creator = $userRepository->findOneByEmail('solve.admin@example.com');
        $planning = $this->createPlanning($planningService, $creator, 'Seniors');
        $line = $lineRepository->findByPlanning($planning)[0];
        $team = $line->getPlanningTeam();
        $planningPeriod = $line->getPlanningPeriod();
        $this->activateRuleSet($ruleSetService, $team);
        $this->addMember($membershipService, $team, $userRepository->findOneByEmail('solve.admin@example.com'), TeamMemberRole::ADMIN);
        $this->addMember($membershipService, $team, $userRepository->findOneByEmail('solve.member@example.com'), TeamMemberRole::MEMBER);

        $createUrl = \sprintf('/api/planning-periods/%s/generations', $planningPeriod->getStableId());
        $client->request('POST', $createUrl, server: $this->authHeader($adminToken));
        $generationStableId = json_decode((string) $client->getResponse()->getContent(), true)['stableId'];
        $client->request('POST', \sprintf('/api/planning-generations/%s/snapshot', $generationStableId), server: $this->authHeader($adminToken));

        $solveUrl = \sprintf('/api/planning-generations/%s/solve', $generationStableId);

        $client->request('POST', $solveUrl, server: $this->authHeader($memberToken));
        self::assertResponseStatusCodeSame(403, 'A plain member must not be able to solve a PlanningGeneration.');

        $client->request('POST', $solveUrl, server: $this->authHeader($adminToken));
        self::assertResponseStatusCodeSame(200);
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame($generationStableId, $data['generationStableId']);
        self::assertSame('COMPLETED', $data['status']);
        self::assertSame('OPTIMAL', $data['strictSolverStatus']);
        self::assertNull($data['partialSolverStatus']);
        self::assertSame('COMPLETE', $data['coverageStatus']);
        self::assertSame(0, $data['assignmentCount'], 'no Duty exists in this fixture — trivially OPTIMAL/COMPLETE with zero assignments');
        self::assertSame(0, $data['unassignedDutyCount']);
        self::assertNull($data['diagnostics']);
        self::assertSame('OR-Tools CP-SAT', $data['solverMetadata']['solverType']);
        self::assertNotSame('', $data['solverMetadata']['solverVersion']);
        self::assertNotNull($data['solverMetadata']['snapshotHash']);
    }

    public function testSolvingANonSnapshottedGenerationReturns409(): void
    {
        $client = static::createClient();
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $userRepository = $container->get(UserRepository::class);
        $membershipService = $container->get(PlanningTeamMembershipService::class);
        $planningService = $container->get(PlanningService::class);
        $lineRepository = $container->get(PlanningLineRepository::class);

        $this->registerUser($client, 'solve.notsnapshotted@example.com', 'correct-horse-battery');
        $token = $this->loginUser($client, 'solve.notsnapshotted@example.com', 'correct-horse-battery');

        $creator = $userRepository->findOneByEmail('solve.notsnapshotted@example.com');
        $planning = $this->createPlanning($planningService, $creator, 'Seniors');
        $line = $lineRepository->findByPlanning($planning)[0];
        $team = $line->getPlanningTeam();
        $planningPeriod = $line->getPlanningPeriod();
        $this->addMember($membershipService, $team, $creator, TeamMemberRole::OWNER);

        $createUrl = \sprintf('/api/planning-periods/%s/generations', $planningPeriod->getStableId());
        $client->request('POST', $createUrl, server: $this->authHeader($token));
        $generationStableId = json_decode((string) $client->getResponse()->getContent(), true)['stableId'];

        // Never snapshotted — still DRAFT.
        $client->request('POST', \sprintf('/api/planning-generations/%s/solve', $generationStableId), server: $this->authHeader($token));
        self::assertResponseStatusCodeSame(409);
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('generation_not_solvable', $data['error']);
    }

    public function testSolvingTheSameGenerationTwiceRejectsTheSecondAttempt(): void
    {
        $client = static::createClient();
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $userRepository = $container->get(UserRepository::class);
        $membershipService = $container->get(PlanningTeamMembershipService::class);
        $ruleSetService = $container->get(PlanningRuleSetService::class);
        $planningService = $container->get(PlanningService::class);
        $lineRepository = $container->get(PlanningLineRepository::class);

        $this->registerUser($client, 'solve.twice@example.com', 'correct-horse-battery');
        $token = $this->loginUser($client, 'solve.twice@example.com', 'correct-horse-battery');

        $creator = $userRepository->findOneByEmail('solve.twice@example.com');
        $planning = $this->createPlanning($planningService, $creator, 'Seniors');
        $line = $lineRepository->findByPlanning($planning)[0];
        $team = $line->getPlanningTeam();
        $planningPeriod = $line->getPlanningPeriod();
        $this->activateRuleSet($ruleSetService, $team);
        $this->addMember($membershipService, $team, $creator, TeamMemberRole::OWNER);

        $createUrl = \sprintf('/api/planning-periods/%s/generations', $planningPeriod->getStableId());
        $client->request('POST', $createUrl, server: $this->authHeader($token));
        $generationStableId = json_decode((string) $client->getResponse()->getContent(), true)['stableId'];
        $client->request('POST', \sprintf('/api/planning-generations/%s/snapshot', $generationStableId), server: $this->authHeader($token));

        $solveUrl = \sprintf('/api/planning-generations/%s/solve', $generationStableId);
        $client->request('POST', $solveUrl, server: $this->authHeader($token));
        self::assertResponseStatusCodeSame(200);

        $client->request('POST', $solveUrl, server: $this->authHeader($token));
        self::assertResponseStatusCodeSame(409);
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('generation_not_solvable', $data['error']);
    }

    public function testSolvingAnUnknownGenerationReturns404(): void
    {
        $client = static::createClient();
        $this->registerUser($client, 'solve.unknown@example.com', 'correct-horse-battery');
        $token = $this->loginUser($client, 'solve.unknown@example.com', 'correct-horse-battery');

        $client->request('POST', '/api/planning-generations/00000000-0000-7000-8000-000000000000/solve', server: $this->authHeader($token));
        self::assertResponseStatusCodeSame(404);
    }
}
