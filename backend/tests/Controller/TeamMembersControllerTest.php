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

final class TeamMembersControllerTest extends WebTestCase
{
    use AuthenticationTestHelpers;
    use PlanningDomainTestHelpers;

    private function authHeader(string $token): array
    {
        return ['HTTP_AUTHORIZATION' => 'Bearer '.$token];
    }

    public function testMemberCanListTeamMembers(): void
    {
        $client = static::createClient();
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $userRepository = $container->get(UserRepository::class);
        $membershipService = $container->get(TeamMembershipService::class);

        $this->registerUser($client, 'members.list@example.com', 'correct-horse-battery');
        $token = $this->loginUser($client, 'members.list@example.com', 'correct-horse-battery');

        $team = $this->createTeam($em);
        $user = $userRepository->findOneByEmail('members.list@example.com');
        $membershipService->addMember($team, $user, TeamMemberRole::MEMBER, $this->date('2026-01-01'));

        $client->request('GET', '/api/teams/'.$team->getStableId().'/members', server: $this->authHeader($token));

        self::assertResponseStatusCodeSame(200);
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertCount(1, $data);
        self::assertSame('MEMBER', $data[0]['role']);
        self::assertArrayNotHasKey('passwordHash', $data[0]);
    }

    public function testNonMemberCannotListTeamMembers(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $this->registerUser($client, 'members.outsider@example.com', 'correct-horse-battery');
        $token = $this->loginUser($client, 'members.outsider@example.com', 'correct-horse-battery');

        $team = $this->createTeam($em);

        $client->request('GET', '/api/teams/'.$team->getStableId().'/members', server: $this->authHeader($token));

        self::assertResponseStatusCodeSame(403);
    }

    public function testUnknownTeamReturns404(): void
    {
        $client = static::createClient();
        $this->registerUser($client, 'members.unknownteam@example.com', 'correct-horse-battery');
        $token = $this->loginUser($client, 'members.unknownteam@example.com', 'correct-horse-battery');

        $client->request('GET', '/api/teams/00000000-0000-7000-8000-000000000000/members', server: $this->authHeader($token));

        self::assertResponseStatusCodeSame(404);
    }

    public function testWithoutTokenReturns401(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $team = $this->createTeam($em);

        $client->request('GET', '/api/teams/'.$team->getStableId().'/members');

        self::assertResponseStatusCodeSame(401);
    }
}
