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

final class PlanningControllerTest extends WebTestCase
{
    use AuthenticationTestHelpers;
    use PlanningDomainTestHelpers;

    private function authHeader(string $token): array
    {
        return ['HTTP_AUTHORIZATION' => 'Bearer '.$token, 'CONTENT_TYPE' => 'application/json'];
    }

    private function createPlanningViaApi(KernelBrowser $client, string $token, string $primaryTeamName = 'Seniors', string $name = 'Gardes Test'): array
    {
        $client->request('POST', '/api/plannings', server: $this->authHeader($token), content: json_encode([
            'name' => $name,
            'startsAt' => '2027-01-01',
            'endsAt' => '2027-05-01',
            'timezone' => 'Europe/Brussels',
            'primaryTeam' => ['name' => $primaryTeamName],
        ]));

        return json_decode((string) $client->getResponse()->getContent(), true);
    }

    /**
     * The primary team is never returned directly by POST /api/plannings
     * (only GET with withLines exposes it) and is never client-supplied
     * either way (docs/decisions.md D079) — fetched straight from the
     * database instead, same convention as every other helper in this
     * suite resolving services post-request (WebTestCase kernel reboot).
     */
    private function resolvePrimaryTeam(PlanningRepository $planningRepository, PlanningTeamRepository $teamRepository, string $planningStableId): PlanningTeam
    {
        $planning = $planningRepository->findOneByStableId($planningStableId);

        return $teamRepository->findByPlanning($planning)[0];
    }

    public function testCreatorCanManageAndOthersCannot(): void
    {
        $client = static::createClient();

        $this->registerUser($client, 'plan.creator@example.com', 'correct-horse-battery');
        $creatorToken = $this->loginUser($client, 'plan.creator@example.com', 'correct-horse-battery');
        $this->registerUser($client, 'plan.outsider@example.com', 'correct-horse-battery');
        $outsiderToken = $this->loginUser($client, 'plan.outsider@example.com', 'correct-horse-battery');
        $this->registerUser($client, 'plan.owner@example.com', 'correct-horse-battery');
        $ownerToken = $this->loginUser($client, 'plan.owner@example.com', 'correct-horse-battery');
        $this->registerUser($client, 'plan.secadmin@example.com', 'correct-horse-battery');
        $secAdminToken = $this->loginUser($client, 'plan.secadmin@example.com', 'correct-horse-battery');

        $data = $this->createPlanningViaApi($client, $creatorToken, 'Seniors');
        self::assertResponseStatusCodeSame(201);
        $planningStableId = $data['stableId'];

        $container = static::getContainer();
        $userRepository = $container->get(UserRepository::class);
        $membershipService = $container->get(PlanningTeamMembershipService::class);
        $team = $this->resolvePrimaryTeam($container->get(PlanningRepository::class), $container->get(PlanningTeamRepository::class), $planningStableId);

        // OWNER of the primary Team — but not the Planning's creator.
        $membershipService->addMember($team, $userRepository->findOneByEmail('plan.owner@example.com'), TeamMemberRole::OWNER, $this->date('2026-01-01'));

        // Add a secondary line, as the creator — it creates its own team,
        // never a client-supplied one (docs/decisions.md D079).
        $client->request('POST', "/api/plannings/{$planningStableId}/lines", server: $this->authHeader($creatorToken), content: json_encode([
            'name' => 'Deuxième ligne',
        ]));
        self::assertResponseStatusCodeSame(201, 'the creator must be able to add a secondary line');
        $lineData = json_decode((string) $client->getResponse()->getContent(), true);

        $container = static::getContainer();
        $userRepository = $container->get(UserRepository::class);
        $membershipService = $container->get(PlanningTeamMembershipService::class);
        $secondaryTeamRepository = $container->get(PlanningTeamRepository::class);
        $secondaryTeam = $secondaryTeamRepository->findOneByStableId($lineData['team']['stableId']);

        // ADMIN of the secondary line's team.
        $membershipService->addMember($secondaryTeam, $userRepository->findOneByEmail('plan.secadmin@example.com'), TeamMemberRole::ADMIN, $this->date('2026-01-01'));

        // 1-2: creator can PATCH.
        $client->request('PATCH', "/api/plannings/{$planningStableId}", server: $this->authHeader($creatorToken), content: json_encode(['name' => 'Renamed by creator']));
        self::assertResponseStatusCodeSame(200);

        // 3: an unrelated outsider cannot.
        $client->request('PATCH', "/api/plannings/{$planningStableId}", server: $this->authHeader($outsiderToken), content: json_encode(['name' => 'Hacked']));
        self::assertResponseStatusCodeSame(403, 'an outsider must not be able to manage the Planning');

        // 4: OWNER of the primary Team, but not the creator.
        $client->request('PATCH', "/api/plannings/{$planningStableId}", server: $this->authHeader($ownerToken), content: json_encode(['name' => 'Hacked by owner']));
        self::assertResponseStatusCodeSame(403, 'a Team OWNER who is not the creator must not be able to manage the Planning');

        // 5: ADMIN of a secondary Team.
        $client->request('PATCH', "/api/plannings/{$planningStableId}", server: $this->authHeader($secAdminToken), content: json_encode(['name' => 'Hacked by secondary admin']));
        self::assertResponseStatusCodeSame(403, 'an ADMIN of a secondary Team must not be able to manage the Planning');
    }

    public function testVisibilityCreatorViewAndManageAssociatedTeamViewOnlyOutsiderNoAccess(): void
    {
        $client = static::createClient();

        $this->registerUser($client, 'plan.vis.creator@example.com', 'correct-horse-battery');
        $creatorToken = $this->loginUser($client, 'plan.vis.creator@example.com', 'correct-horse-battery');
        $this->registerUser($client, 'plan.vis.member@example.com', 'correct-horse-battery');
        $memberToken = $this->loginUser($client, 'plan.vis.member@example.com', 'correct-horse-battery');
        $this->registerUser($client, 'plan.vis.outsider@example.com', 'correct-horse-battery');
        $outsiderToken = $this->loginUser($client, 'plan.vis.outsider@example.com', 'correct-horse-battery');

        $data = $this->createPlanningViaApi($client, $creatorToken);
        $planningStableId = $data['stableId'];

        $container = static::getContainer();
        $userRepository = $container->get(UserRepository::class);
        $membershipService = $container->get(PlanningTeamMembershipService::class);
        $team = $this->resolvePrimaryTeam($container->get(PlanningRepository::class), $container->get(PlanningTeamRepository::class), $planningStableId);
        $membershipService->addMember($team, $userRepository->findOneByEmail('plan.vis.member@example.com'), TeamMemberRole::MEMBER, $this->date('2026-01-01'));

        // creator: VIEW + MANAGE.
        $client->request('GET', "/api/plannings/{$planningStableId}", server: $this->authHeader($creatorToken));
        self::assertResponseStatusCodeSame(200);
        self::assertTrue(json_decode((string) $client->getResponse()->getContent(), true)['canManage'], 'canManage must be true for the creator, so the frontend never needs its own stableId to decide this');

        // associated Team member: VIEW only.
        $client->request('GET', "/api/plannings/{$planningStableId}", server: $this->authHeader($memberToken));
        self::assertResponseStatusCodeSame(200, 'a member of an associated Team must be able to view the Planning');
        self::assertFalse(json_decode((string) $client->getResponse()->getContent(), true)['canManage']);
        $client->request('PATCH', "/api/plannings/{$planningStableId}", server: $this->authHeader($memberToken), content: json_encode(['name' => 'x']));
        self::assertResponseStatusCodeSame(403, 'a member of an associated Team must not be able to manage the Planning');

        // outsider: no access at all.
        $client->request('GET', "/api/plannings/{$planningStableId}", server: $this->authHeader($outsiderToken));
        self::assertResponseStatusCodeSame(403, 'an outsider must have no access to the Planning');
    }

    public function testListReturnsOnlyVisiblePlannings(): void
    {
        $client = static::createClient();

        $this->registerUser($client, 'plan.list.creator@example.com', 'correct-horse-battery');
        $creatorToken = $this->loginUser($client, 'plan.list.creator@example.com', 'correct-horse-battery');
        $this->registerUser($client, 'plan.list.member@example.com', 'correct-horse-battery');
        $memberToken = $this->loginUser($client, 'plan.list.member@example.com', 'correct-horse-battery');
        $this->registerUser($client, 'plan.list.outsider@example.com', 'correct-horse-battery');
        $outsiderToken = $this->loginUser($client, 'plan.list.outsider@example.com', 'correct-horse-battery');

        $data = $this->createPlanningViaApi($client, $creatorToken, 'Seniors', 'Visible Planning');
        $planningStableId = $data['stableId'];

        $container = static::getContainer();
        $userRepository = $container->get(UserRepository::class);
        $membershipService = $container->get(PlanningTeamMembershipService::class);
        $team = $this->resolvePrimaryTeam($container->get(PlanningRepository::class), $container->get(PlanningTeamRepository::class), $planningStableId);
        $membershipService->addMember($team, $userRepository->findOneByEmail('plan.list.member@example.com'), TeamMemberRole::MEMBER, $this->date('2026-01-01'));

        $client->request('GET', '/api/plannings', server: $this->authHeader($creatorToken));
        self::assertResponseStatusCodeSame(200);
        $creatorList = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertNotEmpty(array_filter($creatorList, static fn ($p) => 'Visible Planning' === $p['name']));

        $client->request('GET', '/api/plannings', server: $this->authHeader($memberToken));
        $memberList = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertNotEmpty(array_filter($memberList, static fn ($p) => 'Visible Planning' === $p['name']), 'an associated Team member must see the Planning in their list');

        $client->request('GET', '/api/plannings', server: $this->authHeader($outsiderToken));
        $outsiderList = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertEmpty(array_filter($outsiderList, static fn ($p) => 'Visible Planning' === $p['name']), 'an outsider must not see the Planning in their list');
    }

    public function testCreatorIsNeverAcceptedFromTheClient(): void
    {
        $client = static::createClient();

        $this->registerUser($client, 'plan.spoof@example.com', 'correct-horse-battery');
        $token = $this->loginUser($client, 'plan.spoof@example.com', 'correct-horse-battery');
        $this->registerUser($client, 'plan.victim@example.com', 'correct-horse-battery');

        $userRepository = static::getContainer()->get(UserRepository::class);
        $victim = $userRepository->findOneByEmail('plan.victim@example.com');

        $client->request('POST', '/api/plannings', server: $this->authHeader($token), content: json_encode([
            'name' => 'Spoof attempt',
            'startsAt' => '2027-01-01',
            'endsAt' => '2027-05-01',
            'timezone' => 'Europe/Brussels',
            'primaryTeam' => ['name' => 'Seniors'],
            'creatorStableId' => (string) $victim->getStableId(),
        ]));

        self::assertResponseStatusCodeSame(201);
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('plan.spoof@example.com', $userRepository->findOneByEmail('plan.spoof@example.com')->getEmail());
        self::assertNotSame((string) $victim->getStableId(), $data['creatorStableId'], 'creator must always be #[CurrentUser], never a client-supplied field');
    }

    public function testUnknownPlanningReturns404(): void
    {
        $client = static::createClient();
        $this->registerUser($client, 'plan.unknown@example.com', 'correct-horse-battery');
        $token = $this->loginUser($client, 'plan.unknown@example.com', 'correct-horse-battery');

        $client->request('GET', '/api/plannings/00000000-0000-7000-8000-000000000000', server: $this->authHeader($token));
        self::assertResponseStatusCodeSame(404);
    }

    public function testWithoutTokenReturns401(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/plannings');
        self::assertResponseStatusCodeSame(401);
    }
}
