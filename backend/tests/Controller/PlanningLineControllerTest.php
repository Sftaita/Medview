<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\PlanningTeam;
use App\Entity\TeamMemberRole;
use App\Repository\PlanningRepository;
use App\Repository\PlanningTeamRepository;
use App\Repository\UserRepository;
use App\Service\PlanningTeamMembershipService;
use App\Tests\AuthenticationTestHelpers;
use App\Tests\PlanningDomainTestHelpers;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PlanningLineControllerTest extends WebTestCase
{
    use AuthenticationTestHelpers;
    use PlanningDomainTestHelpers;

    private function authHeader(string $token): array
    {
        return ['HTTP_AUTHORIZATION' => 'Bearer '.$token, 'CONTENT_TYPE' => 'application/json'];
    }

    private function createPlanningViaApi(KernelBrowser $client, string $token, string $primaryTeamName = 'Seniors'): array
    {
        $client->request('POST', '/api/plannings', server: $this->authHeader($token), content: json_encode([
            'name' => 'Gardes Test',
            'startsAt' => '2027-01-01',
            'endsAt' => '2027-05-01',
            'timezone' => 'Europe/Brussels',
            'primaryTeam' => ['name' => $primaryTeamName],
        ]));

        return json_decode((string) $client->getResponse()->getContent(), true);
    }

    private function resolvePrimaryTeam(PlanningRepository $planningRepository, PlanningTeamRepository $teamRepository, string $planningStableId): PlanningTeam
    {
        $planning = $planningRepository->findOneByStableId($planningStableId);

        return $teamRepository->findByPlanning($planning)[0];
    }

    public function testCreatorCanAddSecondAndThirdLineEachWithItsOwnFreshTeam(): void
    {
        $client = static::createClient();

        $this->registerUser($client, 'line.creator@example.com', 'correct-horse-battery');
        $token = $this->loginUser($client, 'line.creator@example.com', 'correct-horse-battery');

        $planning = $this->createPlanningViaApi($client, $token, 'Seniors');
        $planningStableId = $planning['stableId'];
        $linesUrl = "/api/plannings/{$planningStableId}/lines";

        $client->request('POST', $linesUrl, server: $this->authHeader($token), content: json_encode(['name' => 'Deuxième ligne']));
        self::assertResponseStatusCodeSame(201);
        $lineB = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('SECONDARY', $lineB['type']);

        $client->request('POST', $linesUrl, server: $this->authHeader($token), content: json_encode(['name' => 'Renfort']));
        self::assertResponseStatusCodeSame(201);
        $lineC = json_decode((string) $client->getResponse()->getContent(), true);

        // docs/decisions.md D079: never the same PlanningTeam — a client
        // can no longer even express "reuse this team" since no team
        // identifier is accepted in the request body.
        self::assertNotSame($lineB['team']['stableId'], $lineC['team']['stableId']);

        $client->request('GET', "/api/plannings/{$planningStableId}", server: $this->authHeader($token));
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertCount(3, $data['lines']);
    }

    public function testDeletingSecondaryLineIsAllowedPrimaryIsNot(): void
    {
        $client = static::createClient();

        $this->registerUser($client, 'line.delete@example.com', 'correct-horse-battery');
        $token = $this->loginUser($client, 'line.delete@example.com', 'correct-horse-battery');

        $planning = $this->createPlanningViaApi($client, $token, 'Seniors');
        $planningStableId = $planning['stableId'];

        $client->request('POST', "/api/plannings/{$planningStableId}/lines", server: $this->authHeader($token), content: json_encode(['name' => 'Deuxième ligne']));
        $lineB = json_decode((string) $client->getResponse()->getContent(), true);

        $client->request('GET', "/api/plannings/{$planningStableId}", server: $this->authHeader($token));
        $primaryLineStableId = json_decode((string) $client->getResponse()->getContent(), true)['lines'][0]['stableId'];

        $client->request('DELETE', "/api/plannings/{$planningStableId}/lines/{$lineB['stableId']}", server: $this->authHeader($token));
        self::assertResponseStatusCodeSame(204);

        $client->request('DELETE', "/api/plannings/{$planningStableId}/lines/{$primaryLineStableId}", server: $this->authHeader($token));
        self::assertResponseStatusCodeSame(409);
        self::assertSame('primary_line_not_deletable', json_decode((string) $client->getResponse()->getContent(), true)['error']);
    }

    public function testOnlyCreatorCanAddOrDeleteLines(): void
    {
        $client = static::createClient();

        $this->registerUser($client, 'line.creator2@example.com', 'correct-horse-battery');
        $creatorToken = $this->loginUser($client, 'line.creator2@example.com', 'correct-horse-battery');
        $this->registerUser($client, 'line.member@example.com', 'correct-horse-battery');
        $memberToken = $this->loginUser($client, 'line.member@example.com', 'correct-horse-battery');

        $planning = $this->createPlanningViaApi($client, $creatorToken, 'Seniors');
        $planningStableId = $planning['stableId'];

        $container = static::getContainer();
        $userRepository = $container->get(UserRepository::class);
        $membershipService = $container->get(PlanningTeamMembershipService::class);
        $teamA = $this->resolvePrimaryTeam($container->get(PlanningRepository::class), $container->get(PlanningTeamRepository::class), $planningStableId);
        $membershipService->addMember($teamA, $userRepository->findOneByEmail('line.member@example.com'), TeamMemberRole::MEMBER, $this->date('2026-01-01'));

        $client->request('POST', "/api/plannings/{$planningStableId}/lines", server: $this->authHeader($memberToken), content: json_encode(['name' => 'x']));
        self::assertResponseStatusCodeSame(403, 'a mere associated-Team member (not the creator) must not be able to add a line');
    }

    public function testUnknownPlanningReturns404(): void
    {
        $client = static::createClient();

        $this->registerUser($client, 'line.unknownplanning@example.com', 'correct-horse-battery');
        $token = $this->loginUser($client, 'line.unknownplanning@example.com', 'correct-horse-battery');

        $client->request('POST', '/api/plannings/00000000-0000-7000-8000-000000000000/lines', server: $this->authHeader($token), content: json_encode(['name' => 'x']));
        self::assertResponseStatusCodeSame(404);
    }

    public function testWithoutTokenReturns401(): void
    {
        $client = static::createClient();
        $client->request('POST', '/api/plannings/00000000-0000-7000-8000-000000000000/lines');
        self::assertResponseStatusCodeSame(401);
    }
}
