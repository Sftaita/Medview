<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\EventListener\ApiExceptionListener;
use App\Service\Admin\TelemetryRetention;
use App\Tests\AdminTestHelpers;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * The instrumentation behind the administration (docs/admin.md §5-§7,
 * docs/decisions.md D175-D177): what is recorded, when, and — as much —
 * what is not.
 */
final class PlatformTelemetryTest extends WebTestCase
{
    use AdminTestHelpers;
    use ClockSensitiveTrait;

    public function testLoginAndRefreshRecordOneActivityRowPerDay(): void
    {
        $client = self::createClient();
        self::mockTime('2026-12-10 08:00:00 UTC');
        $user = $this->account($client, 'member');

        $client->request('POST', '/api/token/refresh');
        self::assertResponseIsSuccessful();
        $client->request('POST', '/api/token/refresh');

        $rows = $this->connection()->fetchAllAssociative('SELECT activity_date, first_seen_at, last_seen_at FROM user_activity_days a JOIN users u ON u.id = a.user_id WHERE u.email = :email', ['email' => $user['email']]);
        self::assertCount(1, $rows, 'Registration + login + two refreshes on one day = one row.');
        self::assertSame('2026-12-10', $rows[0]['activity_date']);

        // 23:30 UTC is already the next day in Brussels.
        self::mockTime('2026-12-10 23:30:00 UTC');
        $client->request('POST', '/api/token/refresh');
        self::assertSame(
            ['2026-12-10', '2026-12-11'],
            $this->connection()->fetchFirstColumn('SELECT activity_date FROM user_activity_days a JOIN users u ON u.id = a.user_id WHERE u.email = :email ORDER BY 1', ['email' => $user['email']]),
        );

        $columns = $this->connection()->fetchFirstColumn("SELECT column_name FROM information_schema.columns WHERE table_name = 'user_activity_days' ORDER BY ordinal_position");
        self::assertSame(['user_id', 'activity_date', 'first_seen_at', 'last_seen_at'], $columns, 'No IP, no page, no user agent.');
    }

    public function testAFailedLoginIsNotActivity(): void
    {
        $client = self::createClient();
        $this->registerUser($client, 'quiet@example.test', 'correct-horse-battery');
        $client->request('POST', '/api/login', server: ['CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => $this->randomTestIp()], content: (string) json_encode(['email' => 'quiet@example.test', 'password' => 'wrong']));
        self::assertResponseStatusCodeSame(401);

        self::assertSame(0, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM user_activity_days'));
    }

    public function testRegistrationIsAudited(): void
    {
        $client = self::createClient();
        $user = $this->account($client, 'newcomer');

        $row = $this->connection()->fetchAssociative("SELECT e.outcome, e.actor_kind, e.context, u.email FROM platform_audit_events e JOIN users u ON u.id = e.target_user_id WHERE e.type = 'USER_REGISTERED'");
        self::assertSame($user['email'], $row['email']);
        self::assertSame('USER', $row['actor_kind']);
        self::assertSame(['via' => 'classic'], json_decode((string) $row['context'], true));
    }

    public function testTheAuditLogIsAppendOnlyInTheDatabase(): void
    {
        $client = self::createClient();
        $this->account($client, 'newcomer');

        foreach (["UPDATE platform_audit_events SET outcome = 'DENIED'", 'DELETE FROM platform_audit_events'] as $sql) {
            $this->connection()->executeStatement('SAVEPOINT audit_check');
            try {
                $this->connection()->executeStatement($sql);
                self::fail('The trigger must refuse: '.$sql);
            } catch (\Doctrine\DBAL\Exception $exception) {
                self::assertStringContainsString('append-only', $exception->getMessage());
            } finally {
                $this->connection()->executeStatement('ROLLBACK TO SAVEPOINT audit_check');
            }
        }
        self::assertSame(1, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM platform_audit_events'));
    }

    public function testAnUnexpectedErrorIsLoggedWithoutItsMessage(): void
    {
        self::bootKernel();
        $request = Request::create('/api/plannings/x', 'POST');
        $request->attributes->set('_route', 'api_planning_example');
        $event = new ExceptionEvent(self::$kernel, $request, HttpKernelInterface::MAIN_REQUEST, new \RuntimeException('Patient Dupont, chambre 12'));

        self::getContainer()->get(ApiExceptionListener::class)($event);

        self::assertSame(500, $event->getResponse()?->getStatusCode());
        $row = $this->connection()->fetchAssociative('SELECT * FROM technical_error_events');
        self::assertSame('HTTP', $row['source']);
        self::assertSame(\RuntimeException::class, $row['exception_class']);
        self::assertSame('api_planning_example', $row['route']);
        self::assertSame('POST', $row['http_method']);
        self::assertStringNotContainsString('Dupont', (string) json_encode($row));
    }

    public function testAHttpErrorIsNotATechnicalError(): void
    {
        $client = self::createClient();
        $client->request('GET', '/api/this-route-does-not-exist');
        self::assertResponseStatusCodeSame(404);

        self::assertSame(0, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM technical_error_events'));
    }

    public function testRetentionPurgesTelemetryButNeverTheAudit(): void
    {
        $client = self::createClient();
        $user = $this->account($client, 'member');
        self::mockTime('2026-12-10 12:00:00 UTC');
        $userId = (int) $this->connection()->fetchOne('SELECT id FROM users WHERE email = :email', ['email' => $user['email']]);
        $this->connection()->executeStatement('DELETE FROM user_activity_days');
        $this->connection()->executeStatement("INSERT INTO user_activity_days VALUES (:u, '2025-11-04', '2025-11-04 10:00', '2025-11-04 10:00'), (:u, '2025-11-06', '2025-11-06 10:00', '2025-11-06 10:00')", ['u' => $userId]);
        $this->connection()->executeStatement("INSERT INTO technical_error_events (occurred_at, source, exception_class) VALUES ('2026-09-01 10:00', 'HTTP', 'A'), ('2026-09-30 10:00', 'HTTP', 'B')");

        $report = self::getContainer()->get(TelemetryRetention::class)->purge();

        self::assertSame(['activityDays' => 1, 'technicalErrors' => 1], $report);
        self::assertSame(['2025-11-06'], $this->connection()->fetchFirstColumn('SELECT activity_date FROM user_activity_days'));
        self::assertSame(['B'], $this->connection()->fetchFirstColumn('SELECT exception_class FROM technical_error_events'));
        self::assertSame(1, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM platform_audit_events'));
    }

    public function testSystemHealthReportsRealChecksAndUnknownWhenNothingIsKnown(): void
    {
        $client = self::createClient();
        $admin = $this->account($client, 'admin', platformAdmin: true);
        $this->connection()->executeStatement("INSERT INTO technical_error_events (occurred_at, source, exception_class, route, http_method) VALUES (NOW() AT TIME ZONE 'UTC', 'HTTP', 'RuntimeException', 'api_x', 'GET')");

        $report = $this->api($client, 'GET', '/api/admin/system-health', $admin['token']);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));

        self::assertSame('ok', $report['checks']['database']['status']);
        self::assertIsFloat($report['checks']['database']['latencyMs'] + 0.0);
        self::assertSame('ok', $report['checks']['migrations']['status'], 'The test database is migrated.');
        self::assertSame('ok', $report['checks']['jobQueue']['status']);
        // No status file in a test checkout: never "ok" by default.
        self::assertSame('unknown', $report['checks']['backup']['status']);
        self::assertSame('unknown', $report['checks']['restoreTest']['status']);
        self::assertNull($report['version']['release']);
        self::assertSame('test', $report['version']['environment']);
        self::assertSame('warning', $report['errors']['status']);
        self::assertSame(1, $report['errors']['total24h']);
        self::assertSame('RuntimeException', $report['errors']['latest'][0]['exceptionClass']);
        self::assertSame('warning', $report['overall'], 'Worst verified state wins over "unknown".');

        $raw = (string) $client->getResponse()->getContent();
        foreach ([(string) $_SERVER['JWT_PASSPHRASE'], 'postgresql://', 'DATABASE_URL', 'APP_SECRET'] as $secret) {
            self::assertStringNotContainsString($secret, $raw);
        }
    }

    public function testAStuckJobQueueIsReportedAsAnError(): void
    {
        $client = self::createClient();
        $admin = $this->account($client, 'admin', platformAdmin: true);
        $this->connection()->executeStatement("INSERT INTO messenger_messages (body, headers, queue_name, created_at, available_at, delivered_at) VALUES ('{}', '{}', 'planning_jobs', NOW() - INTERVAL '1 hour', NOW() - INTERVAL '1 hour', NULL)");

        $report = $this->api($client, 'GET', '/api/admin/system-health', $admin['token']);
        self::assertSame('error', $report['checks']['jobQueue']['status']);
        self::assertSame(1, $report['checks']['jobQueue']['waiting']);
        self::assertSame('error', $report['overall']);
    }
}
