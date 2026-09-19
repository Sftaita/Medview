<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Repository\UserRepository;
use App\Tests\AuthenticationTestHelpers;
use App\Tests\PlanningDomainTestHelpers;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PlanningTeamMemberControllerTest extends WebTestCase
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

    private function primaryTeamStableId(KernelBrowser $client, string $token, string $planningStableId): string
    {
        $client->request('GET', "/api/plannings/{$planningStableId}", server: $this->authHeader($token));

        return json_decode((string) $client->getResponse()->getContent(), true)['lines'][0]['team']['stableId'];
    }

    public function testCreatorCanAddAMemberAndListReflectsIt(): void
    {
        $client = static::createClient();
        $this->registerUser($client, 'ptm.creator@example.com', 'correct-horse-battery');
        $creatorToken = $this->loginUser($client, 'ptm.creator@example.com', 'correct-horse-battery');
        $this->registerUser($client, 'ptm.candidate@example.com', 'correct-horse-battery');

        $planning = $this->createPlanningViaApi($client, $creatorToken);
        $planningStableId = $planning['stableId'];
        $teamStableId = $this->primaryTeamStableId($client, $creatorToken, $planningStableId);

        $candidate = static::getContainer()->get(UserRepository::class)->findOneByEmail('ptm.candidate@example.com');

        $client->request('POST', "/api/plannings/{$planningStableId}/teams/{$teamStableId}/members", server: $this->authHeader($creatorToken), content: json_encode([
            'userStableId' => (string) $candidate->getStableId(),
            'role' => 'MEMBER',
            'membershipStart' => '2027-01-01',
        ]));
        self::assertResponseStatusCodeSame(201);
        $member = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('MEMBER', $member['role']);
        self::assertNull($member['membershipEnd']);

        $client->request('GET', "/api/plannings/{$planningStableId}/teams/{$teamStableId}/members", server: $this->authHeader($creatorToken));
        self::assertResponseStatusCodeSame(200);
        $members = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertCount(1, $members);
        self::assertSame((string) $candidate->getStableId(), $members[0]['userStableId']);
    }

    /**
     * New scenario 3, exercised through the HTTP layer: adding a User to a
     * second PlanningTeam of the SAME Planning while they already have an
     * open membership there is refused.
     */
    public function testAddingAMemberAlreadyOpenInThisPlanningIsRefused(): void
    {
        $client = static::createClient();
        $this->registerUser($client, 'ptm.conflict.creator@example.com', 'correct-horse-battery');
        $creatorToken = $this->loginUser($client, 'ptm.conflict.creator@example.com', 'correct-horse-battery');
        $this->registerUser($client, 'ptm.conflict.candidate@example.com', 'correct-horse-battery');

        $planning = $this->createPlanningViaApi($client, $creatorToken, 'Seniors');
        $planningStableId = $planning['stableId'];
        $teamAStableId = $this->primaryTeamStableId($client, $creatorToken, $planningStableId);

        $client->request('POST', "/api/plannings/{$planningStableId}/lines", server: $this->authHeader($creatorToken), content: json_encode(['name' => 'Assistants']));
        $teamBStableId = json_decode((string) $client->getResponse()->getContent(), true)['team']['stableId'];

        $candidate = static::getContainer()->get(UserRepository::class)->findOneByEmail('ptm.conflict.candidate@example.com');

        $client->request('POST', "/api/plannings/{$planningStableId}/teams/{$teamAStableId}/members", server: $this->authHeader($creatorToken), content: json_encode([
            'userStableId' => (string) $candidate->getStableId(),
            'role' => 'MEMBER',
            'membershipStart' => '2027-01-01',
        ]));
        self::assertResponseStatusCodeSame(201);

        $client->request('POST', "/api/plannings/{$planningStableId}/teams/{$teamBStableId}/members", server: $this->authHeader($creatorToken), content: json_encode([
            'userStableId' => (string) $candidate->getStableId(),
            'role' => 'MEMBER',
            'membershipStart' => '2027-01-01',
        ]));
        self::assertResponseStatusCodeSame(409);
        self::assertSame('membership_conflict', json_decode((string) $client->getResponse()->getContent(), true)['error']);
    }

    public function testOnlyCreatorCanAddOrEndMembers(): void
    {
        $client = static::createClient();
        $this->registerUser($client, 'ptm.only.creator@example.com', 'correct-horse-battery');
        $creatorToken = $this->loginUser($client, 'ptm.only.creator@example.com', 'correct-horse-battery');
        $this->registerUser($client, 'ptm.only.outsider@example.com', 'correct-horse-battery');
        $outsiderToken = $this->loginUser($client, 'ptm.only.outsider@example.com', 'correct-horse-battery');
        $this->registerUser($client, 'ptm.only.candidate@example.com', 'correct-horse-battery');

        $planning = $this->createPlanningViaApi($client, $creatorToken);
        $planningStableId = $planning['stableId'];
        $teamStableId = $this->primaryTeamStableId($client, $creatorToken, $planningStableId);
        $candidate = static::getContainer()->get(UserRepository::class)->findOneByEmail('ptm.only.candidate@example.com');

        $client->request('POST', "/api/plannings/{$planningStableId}/teams/{$teamStableId}/members", server: $this->authHeader($outsiderToken), content: json_encode([
            'userStableId' => (string) $candidate->getStableId(),
            'role' => 'MEMBER',
            'membershipStart' => '2027-01-01',
        ]));
        self::assertResponseStatusCodeSame(403, 'only the Planning creator can add a team member, never merely an outsider');

        $client->request('POST', "/api/plannings/{$planningStableId}/teams/{$teamStableId}/members", server: $this->authHeader($creatorToken), content: json_encode([
            'userStableId' => (string) $candidate->getStableId(),
            'role' => 'MEMBER',
            'membershipStart' => '2027-01-01',
        ]));
        self::assertResponseStatusCodeSame(201);
        $memberStableId = json_decode((string) $client->getResponse()->getContent(), true)['stableId'];

        $client->request('POST', "/api/plannings/{$planningStableId}/teams/{$teamStableId}/members/{$memberStableId}/end", server: $this->authHeader($outsiderToken), content: '{}');
        self::assertResponseStatusCodeSame(403, 'only the Planning creator can end a membership');

        $client->request('POST', "/api/plannings/{$planningStableId}/teams/{$teamStableId}/members/{$memberStableId}/end", server: $this->authHeader($creatorToken), content: json_encode(['membershipEnd' => '2027-06-01']));
        self::assertResponseStatusCodeSame(200);
        $ended = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('2027-06-01', $ended['membershipEnd']);
    }

    /**
     * Regression test: ending a membership with an implicit "today" default
     * (empty body) must report a clean 422, not an uncaught 500, when
     * "today" falls before the membership's own start date — e.g. a
     * membership deliberately backdated/future-dated for test or migration
     * purposes. Found via manual browser verification of this lot.
     */
    public function testEndingBeforeMembershipStartReturns422InsteadOf500(): void
    {
        $client = static::createClient();
        $this->registerUser($client, 'ptm.future.creator@example.com', 'correct-horse-battery');
        $creatorToken = $this->loginUser($client, 'ptm.future.creator@example.com', 'correct-horse-battery');
        $this->registerUser($client, 'ptm.future.candidate@example.com', 'correct-horse-battery');

        $planning = $this->createPlanningViaApi($client, $creatorToken);
        $planningStableId = $planning['stableId'];
        $teamStableId = $this->primaryTeamStableId($client, $creatorToken, $planningStableId);
        $candidate = static::getContainer()->get(UserRepository::class)->findOneByEmail('ptm.future.candidate@example.com');

        $farFutureStart = (new \DateTimeImmutable('+5 years'))->format('Y-m-d');
        $client->request('POST', "/api/plannings/{$planningStableId}/teams/{$teamStableId}/members", server: $this->authHeader($creatorToken), content: json_encode([
            'userStableId' => (string) $candidate->getStableId(),
            'role' => 'MEMBER',
            'membershipStart' => $farFutureStart,
        ]));
        self::assertResponseStatusCodeSame(201);
        $memberStableId = json_decode((string) $client->getResponse()->getContent(), true)['stableId'];

        $client->request('POST', "/api/plannings/{$planningStableId}/teams/{$teamStableId}/members/{$memberStableId}/end", server: $this->authHeader($creatorToken), content: '{}');
        self::assertResponseStatusCodeSame(422);
        self::assertSame('validation_failed', json_decode((string) $client->getResponse()->getContent(), true)['error']);
    }

    public function testUnknownTeamReturns404(): void
    {
        $client = static::createClient();
        $this->registerUser($client, 'ptm.unknownteam@example.com', 'correct-horse-battery');
        $token = $this->loginUser($client, 'ptm.unknownteam@example.com', 'correct-horse-battery');

        $planning = $this->createPlanningViaApi($client, $token);

        $client->request('GET', "/api/plannings/{$planning['stableId']}/teams/00000000-0000-7000-8000-000000000000/members", server: $this->authHeader($token));
        self::assertResponseStatusCodeSame(404);
    }

    public function testWithoutTokenReturns401(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/plannings/00000000-0000-7000-8000-000000000000/teams/00000000-0000-7000-8000-000000000000/members');
        self::assertResponseStatusCodeSame(401);
    }
}
