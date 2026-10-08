<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Tests\AdminTestHelpers;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * "Activité" (docs/admin.md §6): the audit log, newest first, filterable,
 * paginated; the technical error log kept apart.
 */
final class AdminActivityControllerTest extends WebTestCase
{
    use AdminTestHelpers;

    public function testAuditEventsAreListedNewestFirstWithActorAndTarget(): void
    {
        $client = self::createClient();
        $admin = $this->account($client, 'admin', platformAdmin: true);
        $member = $this->account($client, 'member');
        $this->api($client, 'POST', "/api/admin/users/{$member['stableId']}/revoke-sessions", $admin['token'], ['reason' => 'Téléphone perdu']);

        $page = $this->api($client, 'GET', '/api/admin/audit-events', $admin['token']);
        self::assertResponseIsSuccessful();
        self::assertSame(3, $page['total'], 'Two registrations and one revocation.');
        $latest = $page['items'][0];
        self::assertSame('USER_SESSIONS_REVOKED', $latest['type']);
        self::assertSame('SUCCESS', $latest['outcome']);
        self::assertSame($admin['email'], $latest['actor']['email']);
        self::assertSame($member['email'], $latest['target']['email']);
        self::assertSame('Téléphone perdu', $latest['context']['reason']);
        self::assertSame(['stableId', 'firstName', 'lastName', 'email'], array_keys($latest['actor']));
    }

    public function testFiltersAndPagination(): void
    {
        $client = self::createClient();
        $admin = $this->account($client, 'admin', platformAdmin: true);
        $member = $this->account($client, 'member');
        $this->api($client, 'POST', "/api/admin/users/{$admin['stableId']}/deactivate", $admin['token']); // DENIED (self)
        $this->api($client, 'POST', "/api/admin/users/{$member['stableId']}/deactivate", $admin['token']);

        $byType = $this->api($client, 'GET', '/api/admin/audit-events?type=USER_DEACTIVATED', $admin['token']);
        self::assertSame(2, $byType['total']);

        $denied = $this->api($client, 'GET', '/api/admin/audit-events?outcome=DENIED', $admin['token']);
        self::assertSame(1, $denied['total']);
        self::assertSame('cannot_target_self', $denied['items'][0]['context']['denied']);

        $forMember = $this->api($client, 'GET', "/api/admin/audit-events?user={$member['stableId']}", $admin['token']);
        self::assertSame(['USER_DEACTIVATED', 'USER_REGISTERED'], array_column($forMember['items'], 'type'));

        $paged = $this->api($client, 'GET', '/api/admin/audit-events?perPage=1&page=2', $admin['token']);
        self::assertCount(1, $paged['items']);
        self::assertSame(4, $paged['pageCount']);

        $unknownUser = $this->api($client, 'GET', '/api/admin/audit-events?user=00000000-0000-7000-8000-000000000000', $admin['token']);
        self::assertSame(0, $unknownUser['total']);

        $this->api($client, 'GET', '/api/admin/audit-events?type=EVERYTHING', $admin['token']);
        self::assertResponseStatusCodeSame(400);
    }

    public function testTechnicalErrorsAreAPaginatedSeparateLog(): void
    {
        $client = self::createClient();
        $admin = $this->account($client, 'admin', platformAdmin: true);
        $this->connection()->executeStatement("INSERT INTO technical_error_events (occurred_at, source, exception_class, route, http_method) VALUES ('2026-12-01 10:00', 'HTTP', 'A', 'api_a', 'GET'), ('2026-12-02 10:00', 'WORKER', 'B', 'App\\Message\\RunPlanningJob', NULL)");

        $page = $this->api($client, 'GET', '/api/admin/technical-errors?perPage=1', $admin['token']);
        self::assertSame(2, $page['total']);
        self::assertSame([['occurredAt' => '2026-12-02T10:00:00+00:00', 'source' => 'WORKER', 'exceptionClass' => 'B', 'route' => 'App\\Message\\RunPlanningJob', 'httpMethod' => null]], $page['items']);

        $audit = $this->api($client, 'GET', '/api/admin/audit-events', $admin['token']);
        self::assertNotContains('A', array_column($audit['items'], 'type'), 'Never mixed into the audit log.');
    }
}
