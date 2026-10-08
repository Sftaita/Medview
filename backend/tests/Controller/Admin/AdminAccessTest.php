<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\PlanningService;
use App\Tests\AdminTestHelpers;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Who may reach /api/admin (docs/admin.md §2, docs/decisions.md D174): the
 * global ROLE_PLATFORM_ADMIN only — never an anonymous visitor, a plain
 * user, or the OWNER/ADMIN of a team — and the role can neither be
 * self-granted nor smuggled in through a request body.
 */
final class AdminAccessTest extends WebTestCase
{
    use AdminTestHelpers;

    private const GET_ROUTES = [
        '/api/admin/overview',
        '/api/admin/analytics/timeseries?range=30d',
        '/api/admin/analytics/adoption?range=90d',
        '/api/admin/users',
        '/api/admin/audit-events',
        '/api/admin/technical-errors',
        '/api/admin/system-health',
        '/api/admin/settings',
    ];

    public function testAnonymousVisitorsGet401Everywhere(): void
    {
        $client = self::createClient();
        foreach ([...self::GET_ROUTES, '/api/admin/users/00000000-0000-7000-8000-000000000000'] as $route) {
            $this->api($client, 'GET', $route, null);
            self::assertResponseStatusCodeSame(401, $route);
        }
        $this->api($client, 'POST', '/api/admin/platform-admins', null, ['email' => 'x@example.test', 'password' => 'x']);
        self::assertResponseStatusCodeSame(401);
    }

    public function testAPlainUserGets403OnEveryReadAndWriteRoute(): void
    {
        $client = self::createClient();
        $user = $this->account($client, 'plain');
        $other = $this->account($client, 'other');

        foreach (self::GET_ROUTES as $route) {
            $body = $this->api($client, 'GET', $route, $user['token']);
            self::assertResponseStatusCodeSame(403, $route);
            self::assertArrayNotHasKey('items', $body ?? [], $route);
        }
        foreach (['deactivate', 'reactivate', 'revoke-sessions'] as $action) {
            $this->api($client, 'POST', "/api/admin/users/{$other['stableId']}/{$action}", $user['token']);
            self::assertResponseStatusCodeSame(403, $action);
        }
        $this->api($client, 'POST', "/api/admin/platform-admins/{$other['stableId']}/revoke", $user['token'], ['password' => self::ADMIN_PASSWORD]);
        self::assertResponseStatusCodeSame(403);

        // Nothing happened to the other account.
        $this->api($client, 'GET', '/api/me', $other['token']);
        self::assertResponseIsSuccessful();
    }

    public function testATeamOwnerWithoutTheGlobalRoleIsRefused(): void
    {
        $client = self::createClient();
        $owner = $this->account($client, 'owner');
        $creator = self::getContainer()->get(UserRepository::class)->findOneByEmail($owner['email']);
        self::assertInstanceOf(User::class, $creator);
        // The creator of a planning is OWNER of its primary team.
        self::getContainer()->get(PlanningService::class)->create('Gardes', $creator, new \DateTimeImmutable('2027-01-01'), new \DateTimeImmutable('2027-03-01'), 'Europe/Brussels', 'Ligne');

        foreach (self::GET_ROUTES as $route) {
            $this->api($client, 'GET', $route, $owner['token']);
            self::assertResponseStatusCodeSame(403, $route);
        }
    }

    public function testAPlatformAdminReachesEveryReadRoute(): void
    {
        $client = self::createClient();
        $admin = $this->account($client, 'admin', platformAdmin: true);

        foreach (self::GET_ROUTES as $route) {
            $this->api($client, 'GET', $route, $admin['token']);
            self::assertResponseIsSuccessful($route);
        }
    }

    public function testNobodyCanGrantThemselvesTheRole(): void
    {
        $client = self::createClient();
        $user = $this->account($client, 'climber');

        $this->api($client, 'POST', '/api/admin/platform-admins', $user['token'], ['email' => $user['email'], 'password' => self::ADMIN_PASSWORD]);
        self::assertResponseStatusCodeSame(403);

        $me = $this->api($client, 'GET', '/api/me', $user['token']);
        self::assertFalse($me['platformAdmin']);
        self::assertSame(0, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM users WHERE platform_admin'));
    }

    public function testTheRoleCannotBeSetThroughRegistration(): void
    {
        $client = self::createClient();
        $client->request('POST', '/api/register', server: ['CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => $this->randomTestIp()], content: (string) json_encode(
            $this->registrationPayload('sneaky@example.test', overrides: ['platformAdmin' => true, 'roles' => ['ROLE_PLATFORM_ADMIN']]),
        ));

        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, (int) $this->connection()->fetchOne("SELECT COUNT(*) FROM users WHERE email = 'sneaky@example.test'"));
    }

    public function testMeTellsTheFrontendWhetherTheRoleIsHeld(): void
    {
        $client = self::createClient();
        $user = $this->account($client, 'plain');
        $admin = $this->account($client, 'admin', platformAdmin: true);

        self::assertFalse($this->api($client, 'GET', '/api/me', $user['token'])['platformAdmin']);
        self::assertTrue($this->api($client, 'GET', '/api/me', $admin['token'])['platformAdmin']);
    }

    /**
     * Roles are reloaded from the database on every request, never read from
     * the JWT: the very token issued while the role was held stops opening
     * /api/admin as soon as the role is revoked — and keeps working for the
     * rest of the application (the account itself is untouched).
     */
    public function testARevokedRoleStopsWorkingWithTheTokenAlreadyIssued(): void
    {
        $client = self::createClient();
        $admin = $this->account($client, 'admin', platformAdmin: true);

        $this->api($client, 'GET', '/api/admin/overview', $admin['token']);
        self::assertResponseIsSuccessful();

        $this->setPlatformAdmin($admin['email'], false);

        $this->api($client, 'GET', '/api/admin/overview', $admin['token']);
        self::assertResponseStatusCodeSame(403);
        $this->api($client, 'GET', '/api/me', $admin['token']);
        self::assertResponseIsSuccessful();
    }

    public function testErrorsAreJsonAndNeverLeakDetails(): void
    {
        $client = self::createClient();
        $user = $this->account($client, 'plain');

        $body = $this->api($client, 'GET', '/api/admin/users', $user['token']);
        self::assertResponseStatusCodeSame(403);
        self::assertStringContainsString('application/json', (string) $client->getResponse()->headers->get('Content-Type'));
        self::assertIsArray($body);
        self::assertStringNotContainsString('#', (string) $client->getResponse()->getContent(), 'No stack trace.');
    }
}
