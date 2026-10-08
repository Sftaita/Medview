<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Tests\AdminTestHelpers;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * "Utilisateurs" (docs/admin.md §3-§4): directory, account detail, and the
 * three audited actions with their real security effect on sessions.
 */
final class AdminUserControllerTest extends WebTestCase
{
    use AdminTestHelpers;

    public function testListIsPaginatedSearchableFilterableAndSorted(): void
    {
        $client = self::createClient();
        $admin = $this->account($client, 'admin', platformAdmin: true);
        $this->account($client, 'zoe-search');
        $disabled = $this->account($client, 'disabled');
        $this->connection()->executeStatement('UPDATE users SET active = false WHERE email = :email', ['email' => $disabled['email']]);

        $all = $this->api($client, 'GET', '/api/admin/users?perPage=2&page=1&sort=email&direction=asc', $admin['token']);
        self::assertResponseIsSuccessful();
        self::assertSame(3, $all['total']);
        self::assertSame(2, $all['pageCount']);
        self::assertCount(2, $all['items']);
        self::assertSame([$admin['email'], $disabled['email']], array_column($all['items'], 'email'), 'admin-… < disabled-… < zoe-…');

        $second = $this->api($client, 'GET', '/api/admin/users?perPage=2&page=2&sort=email&direction=asc', $admin['token']);
        self::assertCount(1, $second['items']);
        self::assertStringStartsWith('zoe-search', $second['items'][0]['email']);

        $search = $this->api($client, 'GET', '/api/admin/users?search=ZOE-SE', $admin['token']);
        self::assertSame(1, $search['total']);

        $byName = $this->api($client, 'GET', '/api/admin/users?search=test%20user', $admin['token']);
        self::assertSame(3, $byName['total'], 'First name + last name are searched together.');

        $wildcard = $this->api($client, 'GET', '/api/admin/users?search=%25', $admin['token']);
        self::assertSame(0, $wildcard['total'], 'A LIKE wildcard typed by the admin is a literal character.');

        $onlyDisabled = $this->api($client, 'GET', '/api/admin/users?status=disabled', $admin['token']);
        self::assertSame([$disabled['email']], array_column($onlyDisabled['items'], 'email'));
        self::assertFalse($onlyDisabled['items'][0]['active']);

        $onlyActive = $this->api($client, 'GET', '/api/admin/users?status=active', $admin['token']);
        self::assertSame(2, $onlyActive['total']);

        $row = $onlyActive['items'][0];
        self::assertSame(['stableId', 'email', 'firstName', 'lastName', 'phone', 'active', 'platformAdmin', 'createdAt', 'lastActivityAt', 'planningCount'], array_keys($row));
        self::assertNotNull($row['lastActivityAt'], 'Logging in is a day of activity.');
    }

    public function testInvalidQueryParametersAreRejected(): void
    {
        $client = self::createClient();
        $admin = $this->account($client, 'admin', platformAdmin: true);

        foreach (['sort=password', 'status=deleted', 'direction=up', 'page=0', 'perPage=500', 'search='.str_repeat('a', 101)] as $query) {
            $body = $this->api($client, 'GET', '/api/admin/users?'.$query, $admin['token']);
            self::assertResponseStatusCodeSame(400, $query);
            self::assertSame('invalid_query', $body['error']);
        }
    }

    public function testDetailShowsAccountContextWithoutSecrets(): void
    {
        $client = self::createClient();
        $admin = $this->account($client, 'admin', platformAdmin: true);
        $member = $this->account($client, 'member');

        $detail = $this->api($client, 'GET', "/api/admin/users/{$member['stableId']}", $admin['token']);
        self::assertResponseIsSuccessful();
        self::assertSame($member['email'], $detail['email']);
        self::assertTrue($detail['active']);
        self::assertSame(1, $detail['activeSessionCount']);
        self::assertCount(1, $detail['sessions']);
        self::assertTrue($detail['sessions'][0]['active']);
        self::assertSame(['startedAt', 'lastUsedAt', 'expiresAt', 'active', 'device'], array_keys($detail['sessions'][0]));
        self::assertSame(1, $detail['activity']['activeDaysLast30']);
        self::assertSame([], $detail['plannings']);

        $raw = (string) $client->getResponse()->getContent();
        foreach (['password', 'Hash', 'hash', 'token', 'credentials', '10.9.9.9', 'familyId', 'family_id'] as $secret) {
            self::assertStringNotContainsString($secret, $raw, $secret);
        }

        $this->api($client, 'GET', '/api/admin/users/00000000-0000-7000-8000-000000000000', $admin['token']);
        self::assertResponseStatusCodeSame(404);
        $this->api($client, 'GET', '/api/admin/users/not-a-uuid', $admin['token']);
        self::assertResponseStatusCodeSame(404);
    }

    public function testDeactivationLocksTheAccountOutEverywhereAtOnceAndIsAudited(): void
    {
        $client = self::createClient();
        $admin = $this->account($client, 'admin', platformAdmin: true);
        $member = $this->account($client, 'member');

        $body = $this->api($client, 'POST', "/api/admin/users/{$member['stableId']}/deactivate", $admin['token'], ['reason' => 'Départ du service']);
        self::assertResponseIsSuccessful();
        self::assertFalse($body['active']);
        self::assertSame(0, $body['activeSessionCount']);

        // The access token already in the member's hands is dead immediately…
        $this->api($client, 'GET', '/api/me', $member['token']);
        self::assertResponseStatusCodeSame(401);
        // …logging in again is refused…
        $client->request('POST', '/api/login', server: ['CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => $this->randomTestIp()], content: (string) json_encode(['email' => $member['email'], 'password' => self::ADMIN_PASSWORD]));
        self::assertResponseStatusCodeSame(401);

        $audit = $this->connection()->fetchAssociative("SELECT type, outcome, actor_kind, context FROM platform_audit_events WHERE type = 'USER_DEACTIVATED'");
        self::assertSame('SUCCESS', $audit['outcome']);
        self::assertSame('USER', $audit['actor_kind']);
        $context = json_decode((string) $audit['context'], true);
        self::assertSame('Départ du service', $context['reason']);
        self::assertSame(1, $context['revokedSessions']);

        // Deactivating twice is refused, without a second audit entry.
        $again = $this->api($client, 'POST', "/api/admin/users/{$member['stableId']}/deactivate", $admin['token']);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('already_disabled', $again['error']);
        self::assertSame(1, (int) $this->connection()->fetchOne("SELECT COUNT(*) FROM platform_audit_events WHERE type = 'USER_DEACTIVATED'"));
    }

    public function testTheRefreshCookieOfADeactivatedAccountNoLongerRenewsTheSession(): void
    {
        $memberClient = self::createClient();
        $member = $this->account($memberClient, 'member');
        $memberCookie = $this->getRefreshCookieValue($memberClient);
        self::assertNotNull($memberCookie);

        $admin = $this->account($memberClient, 'admin', platformAdmin: true);
        $this->api($memberClient, 'POST', "/api/admin/users/{$member['stableId']}/deactivate", $admin['token']);
        self::assertResponseIsSuccessful();

        $this->setRefreshCookieValue($memberClient, $memberCookie);
        $memberClient->request('POST', '/api/token/refresh');
        self::assertResponseStatusCodeSame(401);
    }

    public function testReactivationLetsThePersonLogInAgain(): void
    {
        $client = self::createClient();
        $admin = $this->account($client, 'admin', platformAdmin: true);
        $member = $this->account($client, 'member');

        $this->api($client, 'POST', "/api/admin/users/{$member['stableId']}/deactivate", $admin['token']);
        $body = $this->api($client, 'POST', "/api/admin/users/{$member['stableId']}/reactivate", $admin['token'], ['reason' => 'Erreur']);
        self::assertResponseIsSuccessful();
        self::assertTrue($body['active']);

        $this->loginUser($client, $member['email'], self::ADMIN_PASSWORD);
        self::assertSame(1, (int) $this->connection()->fetchOne("SELECT COUNT(*) FROM platform_audit_events WHERE type = 'USER_REACTIVATED' AND outcome = 'SUCCESS'"));

        $again = $this->api($client, 'POST', "/api/admin/users/{$member['stableId']}/reactivate", $admin['token']);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('already_active', $again['error']);
    }

    public function testRevokingSessionsKillsTokensButKeepsTheAccountActive(): void
    {
        $client = self::createClient();
        $admin = $this->account($client, 'admin', platformAdmin: true);
        $member = $this->account($client, 'member');
        $this->loginUser($client, $member['email'], self::ADMIN_PASSWORD); // a second device

        $body = $this->api($client, 'POST', "/api/admin/users/{$member['stableId']}/revoke-sessions", $admin['token']);
        self::assertResponseIsSuccessful();
        self::assertTrue($body['active']);
        self::assertSame(0, $body['activeSessionCount']);

        $this->api($client, 'GET', '/api/me', $member['token']);
        self::assertResponseStatusCodeSame(401);
        $this->loginUser($client, $member['email'], self::ADMIN_PASSWORD);

        $context = json_decode((string) $this->connection()->fetchOne("SELECT context FROM platform_audit_events WHERE type = 'USER_SESSIONS_REVOKED'"), true);
        self::assertSame(2, $context['revokedSessions']);
    }

    public function testAnAdministratorCannotActOnTheirOwnAccountAndTheAttemptIsAudited(): void
    {
        $client = self::createClient();
        $admin = $this->account($client, 'admin', platformAdmin: true);

        foreach (['deactivate' => 'USER_DEACTIVATED', 'reactivate' => 'USER_REACTIVATED', 'revoke-sessions' => 'USER_SESSIONS_REVOKED'] as $action => $type) {
            $body = $this->api($client, 'POST', "/api/admin/users/{$admin['stableId']}/{$action}", $admin['token']);
            self::assertResponseStatusCodeSame(409, $action);
            self::assertSame('cannot_target_self', $body['error']);
            self::assertSame(1, (int) $this->connection()->fetchOne("SELECT COUNT(*) FROM platform_audit_events WHERE type = :type AND outcome = 'DENIED'", ['type' => $type]));
        }

        $this->api($client, 'GET', '/api/me', $admin['token']);
        self::assertResponseIsSuccessful('Still active and logged in.');
    }

    public function testAnotherPlatformAdministratorCannotBeDeactivated(): void
    {
        $client = self::createClient();
        $admin = $this->account($client, 'admin', platformAdmin: true);
        $peer = $this->account($client, 'peer', platformAdmin: true);

        $body = $this->api($client, 'POST', "/api/admin/users/{$peer['stableId']}/deactivate", $admin['token']);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('target_is_platform_admin', $body['error']);
        $this->api($client, 'GET', '/api/me', $peer['token']);
        self::assertResponseIsSuccessful();
    }

    public function testActionBodiesAreStrict(): void
    {
        $client = self::createClient();
        $admin = $this->account($client, 'admin', platformAdmin: true);
        $member = $this->account($client, 'member');

        $body = $this->api($client, 'POST', "/api/admin/users/{$member['stableId']}/deactivate", $admin['token'], ['reason' => 'x', 'active' => true]);
        self::assertResponseStatusCodeSame(422);
        self::assertArrayHasKey('active', $body['violations']);

        $this->api($client, 'POST', "/api/admin/users/{$member['stableId']}/deactivate", $admin['token'], ['reason' => str_repeat('x', 501)]);
        self::assertResponseStatusCodeSame(422);

        $this->api($client, 'GET', '/api/me', $member['token']);
        self::assertResponseIsSuccessful('A rejected request changed nothing.');
    }
}
