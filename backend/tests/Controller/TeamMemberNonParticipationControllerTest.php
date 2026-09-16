<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\TeamMemberRole;
use App\Repository\UserRepository;
use App\Service\TeamMembershipService;
use App\Tests\AuthenticationTestHelpers;
use App\Tests\PlanningDomainTestHelpers;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class TeamMemberNonParticipationControllerTest extends WebTestCase
{
    use AuthenticationTestHelpers;
    use PlanningDomainTestHelpers;

    private function authHeader(string $token): array
    {
        return ['HTTP_AUTHORIZATION' => 'Bearer '.$token, 'CONTENT_TYPE' => 'application/json'];
    }

    public function testAdminCanCreateAndPlainMemberCannot(): void
    {
        $client = static::createClient();
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $userRepository = $container->get(UserRepository::class);
        $membershipService = $container->get(TeamMembershipService::class);

        $this->registerUser($client, 'np.admin@example.com', 'correct-horse-battery');
        $adminToken = $this->loginUser($client, 'np.admin@example.com', 'correct-horse-battery');
        $this->registerUser($client, 'np.member@example.com', 'correct-horse-battery');
        $memberToken = $this->loginUser($client, 'np.member@example.com', 'correct-horse-battery');
        $this->registerUser($client, 'np.target@example.com', 'correct-horse-battery');

        $team = $this->createTeam($em);
        $admin = $userRepository->findOneByEmail('np.admin@example.com');
        $plainMember = $userRepository->findOneByEmail('np.member@example.com');
        $targetUser = $userRepository->findOneByEmail('np.target@example.com');
        $membershipService->addMember($team, $admin, TeamMemberRole::ADMIN, $this->date('2026-01-01'));
        $membershipService->addMember($team, $plainMember, TeamMemberRole::MEMBER, $this->date('2026-01-01'));
        $target = $membershipService->addMember($team, $targetUser, TeamMemberRole::MEMBER, $this->date('2026-01-01'));

        $url = \sprintf('/api/teams/%s/members/%s/non-participation', $team->getStableId(), $target->getStableId());

        $client->request('POST', $url, server: $this->authHeader($memberToken), content: json_encode([
            'startsAt' => '2026-11-01T00:00:00+01:00',
            'endsAt' => '2026-11-30T00:00:00+01:00',
        ]));
        self::assertResponseStatusCodeSame(403, 'A plain member must not be able to manage non-participation.');

        $client->request('POST', $url, server: $this->authHeader($adminToken), content: json_encode([
            'startsAt' => '2026-11-01T00:00:00+01:00',
            'endsAt' => '2026-11-30T00:00:00+01:00',
        ]));
        self::assertResponseStatusCodeSame(201);
    }

    public function testInvalidIntervalIsRejected(): void
    {
        $client = static::createClient();
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $userRepository = $container->get(UserRepository::class);
        $membershipService = $container->get(TeamMembershipService::class);

        $this->registerUser($client, 'np.badrange@example.com', 'correct-horse-battery');
        $token = $this->loginUser($client, 'np.badrange@example.com', 'correct-horse-battery');

        $team = $this->createTeam($em);
        $user = $userRepository->findOneByEmail('np.badrange@example.com');
        $member = $membershipService->addMember($team, $user, TeamMemberRole::OWNER, $this->date('2026-01-01'));

        $url = \sprintf('/api/teams/%s/members/%s/non-participation', $team->getStableId(), $member->getStableId());
        $client->request('POST', $url, server: $this->authHeader($token), content: json_encode([
            'startsAt' => '2026-11-30T00:00:00+01:00',
            'endsAt' => '2026-11-01T00:00:00+01:00',
        ]));

        self::assertResponseStatusCodeSame(422);
    }

    public function testOverlapIsRejectedWith409(): void
    {
        $client = static::createClient();
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $userRepository = $container->get(UserRepository::class);
        $membershipService = $container->get(TeamMembershipService::class);

        $this->registerUser($client, 'np.overlap@example.com', 'correct-horse-battery');
        $token = $this->loginUser($client, 'np.overlap@example.com', 'correct-horse-battery');

        $team = $this->createTeam($em);
        $user = $userRepository->findOneByEmail('np.overlap@example.com');
        $member = $membershipService->addMember($team, $user, TeamMemberRole::OWNER, $this->date('2026-01-01'));

        $url = \sprintf('/api/teams/%s/members/%s/non-participation', $team->getStableId(), $member->getStableId());
        $client->request('POST', $url, server: $this->authHeader($token), content: json_encode([
            'startsAt' => '2026-11-01T00:00:00+01:00',
            'endsAt' => '2026-11-15T00:00:00+01:00',
        ]));
        self::assertResponseStatusCodeSame(201);

        $client->request('POST', $url, server: $this->authHeader($token), content: json_encode([
            'startsAt' => '2026-11-10T00:00:00+01:00',
            'endsAt' => '2026-11-20T00:00:00+01:00',
        ]));
        self::assertResponseStatusCodeSame(409);
    }

    public function testMemberCanViewOwnPeriodsButNotAnotherMembers(): void
    {
        $client = static::createClient();
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $userRepository = $container->get(UserRepository::class);
        $membershipService = $container->get(TeamMembershipService::class);

        $this->registerUser($client, 'np.viewer1@example.com', 'correct-horse-battery');
        $viewer1Token = $this->loginUser($client, 'np.viewer1@example.com', 'correct-horse-battery');
        $this->registerUser($client, 'np.viewer2@example.com', 'correct-horse-battery');
        $viewer2Token = $this->loginUser($client, 'np.viewer2@example.com', 'correct-horse-battery');

        $team = $this->createTeam($em);
        $user1 = $userRepository->findOneByEmail('np.viewer1@example.com');
        $user2 = $userRepository->findOneByEmail('np.viewer2@example.com');
        $member1 = $membershipService->addMember($team, $user1, TeamMemberRole::MEMBER, $this->date('2026-01-01'));
        $membershipService->addMember($team, $user2, TeamMemberRole::MEMBER, $this->date('2026-01-01'));

        $url = \sprintf('/api/teams/%s/members/%s/non-participation', $team->getStableId(), $member1->getStableId());

        $client->request('GET', $url, server: $this->authHeader($viewer1Token));
        self::assertResponseStatusCodeSame(200, 'A member must be able to view their own non-participation periods.');

        $client->request('GET', $url, server: $this->authHeader($viewer2Token));
        self::assertResponseStatusCodeSame(403, 'A member must not view another member\'s non-participation periods.');
    }

    public function testMemberFromADifferentTeamIsNotFound(): void
    {
        $client = static::createClient();
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $userRepository = $container->get(UserRepository::class);
        $membershipService = $container->get(TeamMembershipService::class);

        $this->registerUser($client, 'np.crossteam@example.com', 'correct-horse-battery');
        $token = $this->loginUser($client, 'np.crossteam@example.com', 'correct-horse-battery');

        $teamA = $this->createTeam($em, 'Team A', 'team-a');
        $teamB = $this->createTeam($em, 'Team B', 'team-b');
        $user = $userRepository->findOneByEmail('np.crossteam@example.com');
        $membershipService->addMember($teamA, $user, TeamMemberRole::OWNER, $this->date('2026-01-01'));
        $otherUser = $this->createUser($em);
        $memberOfB = $membershipService->addMember($teamB, $otherUser, TeamMemberRole::MEMBER, $this->date('2026-01-01'));

        $url = \sprintf('/api/teams/%s/members/%s/non-participation', $teamA->getStableId(), $memberOfB->getStableId());
        $client->request('GET', $url, server: $this->authHeader($token));

        self::assertResponseStatusCodeSame(404);
    }

    public function testDeleteAsAdmin(): void
    {
        $client = static::createClient();
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $userRepository = $container->get(UserRepository::class);
        $membershipService = $container->get(TeamMembershipService::class);

        $this->registerUser($client, 'np.delete@example.com', 'correct-horse-battery');
        $token = $this->loginUser($client, 'np.delete@example.com', 'correct-horse-battery');

        $team = $this->createTeam($em);
        $user = $userRepository->findOneByEmail('np.delete@example.com');
        $member = $membershipService->addMember($team, $user, TeamMemberRole::OWNER, $this->date('2026-01-01'));

        $baseUrl = \sprintf('/api/teams/%s/members/%s/non-participation', $team->getStableId(), $member->getStableId());
        $client->request('POST', $baseUrl, server: $this->authHeader($token), content: json_encode([
            'startsAt' => '2026-11-01T00:00:00+01:00',
            'endsAt' => '2026-11-15T00:00:00+01:00',
        ]));
        $stableId = json_decode((string) $client->getResponse()->getContent(), true)['stableId'];

        $client->request('DELETE', $baseUrl.'/'.$stableId, server: $this->authHeader($token));
        self::assertResponseStatusCodeSame(204);

        $client->request('GET', $baseUrl, server: $this->authHeader($token));
        self::assertSame([], json_decode((string) $client->getResponse()->getContent(), true));
    }

    public function testWithoutTokenReturns401(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $membershipService = static::getContainer()->get(TeamMembershipService::class);

        $team = $this->createTeam($em);
        $user = $this->createUser($em);
        $member = $membershipService->addMember($team, $user, TeamMemberRole::OWNER, $this->date('2026-01-01'));

        $url = \sprintf('/api/teams/%s/members/%s/non-participation', $team->getStableId(), $member->getStableId());
        $client->request('GET', $url);

        self::assertResponseStatusCodeSame(401);
    }
}
