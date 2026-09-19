<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\TeamMemberRole;
use App\Entity\UserAvailabilityType;
use App\Repository\UserRepository;
use App\Service\DutyMaterializationService;
use App\Service\PlanningRuleSetService;
use App\Service\PlanningTeamMembershipService;
use App\Service\UserAvailabilityService;
use App\Tests\AuthenticationTestHelpers;
use App\Tests\PlanningDomainTestHelpers;
use App\Tests\PlanningGenerationTestHelpers;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class EligibilityControllerTest extends WebTestCase
{
    use AuthenticationTestHelpers;
    use PlanningDomainTestHelpers;
    use PlanningGenerationTestHelpers;

    private function authHeader(string $token): array
    {
        return ['HTTP_AUTHORIZATION' => 'Bearer '.$token, 'CONTENT_TYPE' => 'application/json'];
    }

    public function testAdminCanReadEligibilityAndPlainMemberCannot(): void
    {
        $client = static::createClient();
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $userRepository = $container->get(UserRepository::class);
        $membershipService = $container->get(PlanningTeamMembershipService::class);
        $ruleSetService = $container->get(PlanningRuleSetService::class);
        $dutyMaterialization = $container->get(DutyMaterializationService::class);
        $availabilityService = $container->get(UserAvailabilityService::class);

        $this->registerUser($client, 'elig.admin@example.com', 'correct-horse-battery');
        $adminToken = $this->loginUser($client, 'elig.admin@example.com', 'correct-horse-battery');
        $this->registerUser($client, 'elig.member@example.com', 'correct-horse-battery');
        $memberToken = $this->loginUser($client, 'elig.member@example.com', 'correct-horse-battery');

        $team = $this->createTeam($em);
        $planningPeriod = $this->createPlanningPeriod($em, $team, '2027-01-01', '2027-05-01');
        $this->activateRuleSet($ruleSetService, $team);
        $admin = $userRepository->findOneByEmail('elig.admin@example.com');
        $plainMember = $userRepository->findOneByEmail('elig.member@example.com');
        $adminTeamMember = $this->addMember($membershipService, $team, $admin, TeamMemberRole::ADMIN);
        $this->addMember($membershipService, $team, $plainMember, TeamMemberRole::MEMBER);

        $dutyType = $this->createDutyType($em, $team);
        $duty = $this->createDuty($dutyMaterialization, $planningPeriod, $dutyType, '2027-02-01 08:00', '2027-02-01 20:00');
        $this->addAvailability($availabilityService, $admin, UserAvailabilityType::UNAVAILABLE, '2027-02-01 08:00', '2027-02-01 20:00');

        $client->request('POST', \sprintf('/api/planning-periods/%s/generations', $planningPeriod->getStableId()), server: $this->authHeader($adminToken));
        $generationStableId = json_decode((string) $client->getResponse()->getContent(), true)['stableId'];
        $client->request('POST', \sprintf('/api/planning-generations/%s/snapshot', $generationStableId), server: $this->authHeader($adminToken));
        self::assertResponseStatusCodeSame(201);

        $eligibilityUrl = \sprintf('/api/planning-generations/%s/eligibility', $generationStableId);

        $client->request('GET', $eligibilityUrl, server: $this->authHeader($memberToken));
        self::assertResponseStatusCodeSame(403, 'a plain member must not be able to inspect the eligibility matrix');

        $client->request('GET', $eligibilityUrl, server: $this->authHeader($adminToken));
        self::assertResponseStatusCodeSame(200);
        $data = json_decode((string) $client->getResponse()->getContent(), true);

        self::assertSame($generationStableId, $data['generationStableId']);
        self::assertSame(1, $data['summary']['dutyUnitCount']);
        self::assertSame(2, $data['summary']['candidateCount']);
        self::assertSame(1, $data['summary']['eligiblePairs'], 'the plain member remains eligible');
        self::assertSame(1, $data['summary']['excludedPairs'], 'the admin is excluded by their own UNAVAILABLE declaration');

        $dutyUnit = $data['dutyUnits'][0];
        self::assertSame([$duty->getLocalDate()->format('Y-m-d')], $dutyUnit['dates']);
        self::assertFalse($dutyUnit['grouped']);

        $adminCandidate = current(array_filter($dutyUnit['candidates'], static fn ($c) => $c['memberStableId'] === (string) $adminTeamMember->getStableId()));
        self::assertFalse($adminCandidate['eligible']);
        self::assertTrue($adminCandidate['structuralOpportunity']);
        self::assertSame('UNAVAILABLE', $adminCandidate['exclusions'][0]['reason']);
        self::assertSame('HARD', $adminCandidate['exclusions'][0]['tier']);
    }

    public function testEligibilityBeforeSnapshotReturns404(): void
    {
        $client = static::createClient();
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $userRepository = $container->get(UserRepository::class);
        $membershipService = $container->get(PlanningTeamMembershipService::class);

        $this->registerUser($client, 'elig.nosnapshot@example.com', 'correct-horse-battery');
        $token = $this->loginUser($client, 'elig.nosnapshot@example.com', 'correct-horse-battery');

        $team = $this->createTeam($em);
        $planningPeriod = $this->createPlanningPeriod($em, $team);
        $this->addMember($membershipService, $team, $userRepository->findOneByEmail('elig.nosnapshot@example.com'), TeamMemberRole::OWNER);

        $client->request('POST', \sprintf('/api/planning-periods/%s/generations', $planningPeriod->getStableId()), server: $this->authHeader($token));
        $generationStableId = json_decode((string) $client->getResponse()->getContent(), true)['stableId'];

        $client->request('GET', \sprintf('/api/planning-generations/%s/eligibility', $generationStableId), server: $this->authHeader($token));
        self::assertResponseStatusCodeSame(404);
    }

    public function testUnknownGenerationReturns404(): void
    {
        $client = static::createClient();
        $this->registerUser($client, 'elig.unknown@example.com', 'correct-horse-battery');
        $token = $this->loginUser($client, 'elig.unknown@example.com', 'correct-horse-battery');

        $client->request('GET', '/api/planning-generations/00000000-0000-7000-8000-000000000000/eligibility', server: $this->authHeader($token));
        self::assertResponseStatusCodeSame(404);
    }

    public function testWithoutTokenReturns401(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/planning-generations/00000000-0000-7000-8000-000000000000/eligibility');
        self::assertResponseStatusCodeSame(401);
    }
}
