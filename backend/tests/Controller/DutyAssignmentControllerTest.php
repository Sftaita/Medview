<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\PlanningGeneration;
use App\Entity\TeamMemberRole;
use App\Repository\UserRepository;
use App\Service\DutyMaterializationService;
use App\Service\PlanningRuleSetService;
use App\Service\PlanningSnapshotService;
use App\Service\PlanningTeamMembershipService;
use App\Tests\AuthenticationTestHelpers;
use App\Tests\PlanningDomainTestHelpers;
use App\Tests\PlanningGenerationTestHelpers;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The former technical assignment endpoint, closed by docs/decisions.md D197:
 * 410 for every caller, nothing read, nothing written. Its structural
 * invariants still guard the controlled write paths: tests/Service/DutyAssignmentServiceTest.
 */
final class DutyAssignmentControllerTest extends WebTestCase
{
    use AuthenticationTestHelpers;
    use PlanningDomainTestHelpers;
    use PlanningGenerationTestHelpers;

    private function authHeader(string $token): array
    {
        return ['HTTP_AUTHORIZATION' => 'Bearer '.$token, 'CONTENT_TYPE' => 'application/json'];
    }

    public function testTheEndpointIsClosedForEveryRoleAndWritesNothing(): void
    {
        $client = static::createClient();
        $tokens = [];
        foreach (['OWNER', 'ADMIN', 'MEMBER'] as $role) {
            $email = 'da.'.strtolower($role).'@example.com';
            $this->registerUser($client, $email, 'correct-horse-battery');
            $tokens[$role] = $this->loginUser($client, $email, 'correct-horse-battery');
        }

        // After the requests: each one may reboot the kernel, and with it the EntityManager.
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $users = $container->get(UserRepository::class);
        $membership = $container->get(PlanningTeamMembershipService::class);

        $team = $this->createTeam($em);
        $period = $this->createPlanningPeriod($em, $team, '2027-01-01', '2027-05-01');
        $this->activateRuleSet($container->get(PlanningRuleSetService::class), $team);
        $owner = $this->addMember($membership, $team, $users->findOneByEmail('da.owner@example.com'), TeamMemberRole::OWNER);
        $this->addMember($membership, $team, $users->findOneByEmail('da.admin@example.com'), TeamMemberRole::ADMIN);
        $this->addMember($membership, $team, $users->findOneByEmail('da.member@example.com'), TeamMemberRole::MEMBER);
        $duty = $this->createDuty($container->get(DutyMaterializationService::class), $period, $this->createDutyType($em, $team), '2027-02-01 08:00', '2027-02-01 20:00');
        $generation = new PlanningGeneration($period);
        $em->persist($generation);
        $em->flush();
        $container->get(PlanningSnapshotService::class)->createSnapshot($generation);

        // Exactly what it used to accept, from each role it used to accept or refuse.
        $payload = json_encode(['dutyStableId' => (string) $duty->getStableId(), 'teamMemberStableId' => (string) $owner->getStableId(), 'locked' => true]);
        foreach ($tokens as $role => $token) {
            $client->request('POST', \sprintf('/api/planning-generations/%s/assignments', $generation->getStableId()), server: $this->authHeader($token), content: $payload);

            self::assertResponseStatusCodeSame(410, $role);
            $body = json_decode((string) $client->getResponse()->getContent(), true);
            self::assertSame('endpoint_removed', $body['error'], $role);
            self::assertStringContainsString('/reassign', $body['message'], 'the answer names the controlled path');
        }

        $db = $container->get(Connection::class);
        self::assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM duty_assignments'));
        self::assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM duty_assignment_events'));
    }

    public function testAnUnknownGenerationGetsTheSameAnswer(): void
    {
        $client = static::createClient();
        $this->registerUser($client, 'da.unknown@example.com', 'correct-horse-battery');
        $token = $this->loginUser($client, 'da.unknown@example.com', 'correct-horse-battery');

        $client->request('POST', '/api/planning-generations/00000000-0000-7000-8000-000000000000/assignments', server: $this->authHeader($token), content: '{}');

        self::assertResponseStatusCodeSame(410, 'nothing is looked up: no answer tells whether a generation exists');
    }

    public function testWithoutTokenReturns401(): void
    {
        $client = static::createClient();

        $client->request('POST', '/api/planning-generations/00000000-0000-7000-8000-000000000000/assignments', server: ['CONTENT_TYPE' => 'application/json'], content: '{}');

        self::assertResponseStatusCodeSame(401);
    }
}
