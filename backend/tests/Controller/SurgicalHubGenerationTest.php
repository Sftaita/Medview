<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Repository\PlanningRepository;
use App\Repository\PlanningTeamMemberRepository;
use App\Repository\SurgicalHubLinkRepository;
use App\Service\SurgicalHub\SurgicalHubFreshnessGate;
use App\Service\SurgicalHub\SurgicalHubLeaveSyncService;
use App\Service\SurgicalHub\SurgicalHubSyncError;
use App\Tests\FakeSurgicalHub;
use App\Tests\PlanningPilotTestHelpers;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * SurgicalHub leave and generation (docs/surgicalhub-integration.md §7.5,
 * decisions D8 and D10): a synchronisation is always attempted at launch,
 * outdated data blocks unless the creator overrides, imported leave reaches
 * the snapshot and the eligibility with its provenance, and a snapshot never
 * changes afterwards. A real OR-Tools solve runs, as in PlanningLaunchControllerTest.
 */
final class SurgicalHubGenerationTest extends WebTestCase
{
    use ClockSensitiveTrait;
    use PlanningPilotTestHelpers;

    private const SECRET = 'test-surgicalhub-to-medvue-secret-0123456789';

    protected function setUp(): void
    {
        parent::setUp();
        FakeSurgicalHub::reset();
        self::mockTime('2026-12-10 09:00:00 Europe/Brussels');
    }

    protected function tearDown(): void
    {
        FakeSurgicalHub::reset();
        parent::tearDown();
    }

    /** Associates $email's account (logged in as $token) with SurgicalHub account $surgicalHubUserId; returns the linkId. */
    private function associate(KernelBrowser $client, string $token, string $surgicalHubUserId): string
    {
        $code = $this->api($client, 'POST', '/api/me/surgicalhub/link-code', [], $token)['code'];
        $client->request('POST', '/api/integrations/surgicalhub/v1/link-codes/redeem', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.self::SECRET, 'CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => $this->randomTestIp(),
        ], content: json_encode(['code' => $code, 'surgicalHubUserId' => $surgicalHubUserId, 'surgicalHubDisplayName' => 'X', 'actorDisplayName' => 'X']));
        self::assertResponseIsSuccessful();

        return json_decode((string) $client->getResponse()->getContent(), true)['linkId'];
    }

    /**
     * @param array<string, mixed> $s
     *
     * @return array<string, mixed> the 202 body, or the refusal
     */
    private function requestLaunch(KernelBrowser $client, array $s, string $token, array $body = []): array
    {
        return $this->api($client, 'POST', "/api/plannings/{$s['planningId']}/generations", $body, $token);
    }

    /**
     * @param array<string, mixed> $s
     *
     * @return array<string, mixed> the finished job's per-line outcome
     */
    private function runAndRead(KernelBrowser $client, array $s): array
    {
        $this->runQueuedPlanningJobs();

        return $this->api($client, 'GET', "/api/plannings/{$s['planningId']}/jobs/latest", token: $s['creator'])['job']['outcome'];
    }

    private function failSurgicalHubFor(string $linkId): void
    {
        FakeSurgicalHub::override(static fn (string $method, string $url): ?MockResponse => str_contains($url, $linkId) ? new MockResponse('down', ['http_code' => 503]) : null);
    }

    public function testImportedLeaveReachesTheSnapshotAndExcludesWithItsProvenance(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);
        $this->prepareGeneration($s['planningId']);
        $bobLink = $this->associate($client, $s['bob'], '7001');
        // Bob's SurgicalHub leave covers the first duty (2027-01-05); nobody synchronised yet.
        FakeSurgicalHub::setAbsences($bobLink, [['id' => '42', 'startDate' => '2027-01-05', 'endDate' => '2027-01-05']]);

        $preflight = $this->api($client, 'GET', "/api/plannings/{$s['planningId']}/generation-preflight", token: $s['creator']);
        self::assertCount(1, $preflight['surgicalHub']);
        self::assertNull($preflight['surgicalHub'][0]['lastSuccessfulSyncAt']);
        self::assertSame([], FakeSurgicalHub::requests(), 'The preflight never calls SurgicalHub.');

        $accepted = $this->requestLaunch($client, $s, $s['creator']);
        self::assertResponseStatusCodeSame(202);
        self::assertSame(['warnings' => [], 'overridden' => []], $accepted['surgicalHub']);
        $requestsAtLaunch = \count(FakeSurgicalHub::requests());
        self::assertSame(1, $requestsAtLaunch, 'The launch synchronised the associated participant.');

        $line = $this->runAndRead($client, $s)['lines'][0];
        self::assertSame('COMPLETED', $line['status']);
        self::assertSame(1, $line['snapshot']['unavailableCount']);
        self::assertCount($requestsAtLaunch, FakeSurgicalHub::requests(), 'The worker never calls SurgicalHub.');

        $snapshot = $this->api($client, 'GET', "/api/planning-generations/{$line['generationStableId']}/snapshot", token: $s['admin']);
        $bobStableId = (string) $this->userOf('bob@example.com')->getStableId();
        $bob = array_values(array_filter($snapshot['members'], static fn (array $m): bool => $m['sourceUserStableId'] === $bobStableId))[0];
        self::assertSame('SURGICAL_HUB', $bob['availabilityPeriods'][0]['provenance']);

        $eligibility = $this->api($client, 'GET', "/api/planning-generations/{$line['generationStableId']}/eligibility", token: $s['admin']);
        self::assertStringContainsString('"provenance":"SURGICAL_HUB"', (string) json_encode($eligibility));

        // The leave changes in SurgicalHub afterwards: that snapshot never moves (D10).
        FakeSurgicalHub::setAbsences($bobLink, []);
        $this->api($client, 'POST', '/api/me/surgicalhub/sync', [], $s['bob']);
        self::assertSame($snapshot['members'], $this->api($client, 'GET', "/api/planning-generations/{$line['generationStableId']}/snapshot", token: $s['admin'])['members']);
    }

    public function testAFailedRefreshWithRecentDataIsAWarningAndTheLaunchGoesOn(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);
        $this->prepareGeneration($s['planningId']);
        $bobLink = $this->associate($client, $s['bob'], '7002');
        FakeSurgicalHub::setAbsences($bobLink, []);
        $this->api($client, 'POST', '/api/me/surgicalhub/sync', [], $s['bob']);
        self::mockTime('2026-12-10 20:00:00 Europe/Brussels'); // 11 h later
        $this->failSurgicalHubFor($bobLink);

        $accepted = $this->requestLaunch($client, $s, $s['admin']);

        self::assertResponseStatusCodeSame(202);
        self::assertCount(1, $accepted['surgicalHub']['warnings']);
        self::assertSame((string) $this->userOf('bob@example.com')->getStableId(), $accepted['surgicalHub']['warnings'][0]['userStableId']);
        self::assertSame('server_error', $accepted['surgicalHub']['warnings'][0]['error']);
        self::assertSame([], $accepted['surgicalHub']['overridden']);
    }

    public function testOutdatedDataBlocksAndOnlyTheCreatorCanOverrideExplicitly(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);
        $this->prepareGeneration($s['planningId']);
        $bobLink = $this->associate($client, $s['bob'], '7003');
        FakeSurgicalHub::setAbsences($bobLink, []);
        $this->api($client, 'POST', '/api/me/surgicalhub/sync', [], $s['bob']);
        self::mockTime('2026-12-11 09:00:00 Europe/Brussels'); // exactly 24 h later: blocking
        $this->failSurgicalHubFor($bobLink);

        $refused = $this->requestLaunch($client, $s, $s['admin']);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('surgicalhub_data_stale', $refused['error']);
        self::assertCount(1, $refused['participants']);
        self::assertFalse($refused['canOverride'], 'A team ADMIN cannot override.');
        self::assertNull($this->api($client, 'GET', "/api/plannings/{$s['planningId']}/jobs/latest", token: $s['creator'])['job'], 'Nothing was queued.');

        $this->requestLaunch($client, $s, $s['admin'], ['overrideStaleSurgicalHubData' => true]);
        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, $this->overrideEvents());

        // Anything but an explicit true is no override.
        $this->requestLaunch($client, $s, $s['creator'], ['overrideStaleSurgicalHubData' => 'yes']);
        self::assertResponseStatusCodeSame(409);

        $accepted = $this->requestLaunch($client, $s, $s['creator'], ['overrideStaleSurgicalHubData' => true]);
        self::assertResponseStatusCodeSame(202);
        self::assertCount(1, $accepted['surgicalHub']['overridden']);
        self::assertSame(1, $this->overrideEvents());
        $details = json_decode((string) $this->connection()->fetchOne("SELECT details FROM surgical_hub_link_events WHERE kind = 'STALE_DATA_OVERRIDDEN'"), true);
        self::assertSame($s['planningId'], $details['planningStableId']);
        self::assertSame($accepted['job']['stableId'], $details['jobStableId']);
        self::assertSame('server_error', $details['error']);

        // An override is never a successful synchronisation.
        $bob = $this->api($client, 'GET', '/api/me/surgicalhub', token: $s['bob'])['link'];
        self::assertStringStartsWith('2026-12-10', (string) $bob['lastSuccessfulSyncAt']);
    }

    public function testNeverSynchronisedDataBlocks(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);
        $this->prepareGeneration($s['planningId']);
        $bobLink = $this->associate($client, $s['bob'], '7004');
        $this->failSurgicalHubFor($bobLink);

        $this->requestLaunch($client, $s, $s['creator']);

        self::assertResponseStatusCodeSame(409);
    }

    public function testParticipantsWithoutAssociationAreNeverConcerned(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);
        $this->prepareGeneration($s['planningId']);
        // SurgicalHub is down for everyone — irrelevant: nobody is associated.
        FakeSurgicalHub::override(static fn (): MockResponse => new MockResponse('down', ['http_code' => 503]));

        $accepted = $this->requestLaunch($client, $s, $s['creator']);

        self::assertResponseStatusCodeSame(202);
        self::assertSame(['warnings' => [], 'overridden' => []], $accepted['surgicalHub']);
        self::assertSame([], FakeSurgicalHub::requests());
    }

    public function testAnOutageCallsSurgicalHubOnceAndClassifiesEveryoneByTheirLastSuccess(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);
        $this->prepareGeneration($s['planningId']);
        $links = [];
        foreach (['alice' => '7101', 'bob' => '7102', 'admin' => '7103'] as $who => $surgicalHubUserId) {
            $links[$who] = $this->associate($client, $s[$who], $surgicalHubUserId);
            FakeSurgicalHub::setAbsences($links[$who], []);
        }
        // Alice and Bob were synchronised this morning; the admin never was.
        $this->api($client, 'POST', '/api/me/surgicalhub/sync', [], $s['alice']);
        $this->api($client, 'POST', '/api/me/surgicalhub/sync', [], $s['bob']);
        self::mockTime('2026-12-10 15:00:00 Europe/Brussels');
        // SurgicalHub is down: every call fails at the network level.
        FakeSurgicalHub::override(static fn (): MockResponse => new MockResponse([new \RuntimeException('connection refused')]));
        $before = \count(FakeSurgicalHub::requests());

        $refused = $this->requestLaunch($client, $s, $s['creator']);

        self::assertSame(1, \count(FakeSurgicalHub::requests()) - $before, 'One failed call is enough: the others are not attempted.');
        self::assertResponseStatusCodeSame(409, 'The admin was never synchronised: blocking.');
        self::assertCount(1, $refused['participants']);
        self::assertSame('unreachable', $refused['participants'][0]['error']);

        // With the creator's override, the two recent ones are warnings, the admin an override.
        $accepted = $this->requestLaunch($client, $s, $s['creator'], ['overrideStaleSurgicalHubData' => true]);
        self::assertResponseStatusCodeSame(202);
        self::assertCount(2, $accepted['surgicalHub']['warnings']);
        self::assertCount(1, $accepted['surgicalHub']['overridden']);
    }

    public function testASuspendedParticipantIsNeverCalledAndBlocksOnceTooOld(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);
        $this->prepareGeneration($s['planningId']);
        $bobLink = $this->associate($client, $s['bob'], '7104');
        FakeSurgicalHub::setAbsences($bobLink, []);
        $this->api($client, 'POST', '/api/me/surgicalhub/sync', [], $s['bob']);
        FakeSurgicalHub::markGone($bobLink, 'link_not_found');
        $this->api($client, 'POST', '/api/me/surgicalhub/sync', [], $s['bob']);
        self::mockTime('2026-12-12 09:00:00 Europe/Brussels');
        $before = \count(FakeSurgicalHub::requests());

        $refused = $this->requestLaunch($client, $s, $s['creator']);

        self::assertResponseStatusCodeSame(409);
        self::assertSame('link_suspended', $refused['participants'][0]['error']);
        self::assertCount($before, FakeSurgicalHub::requests(), 'A suspended association is never read.');
        $preflight = $this->api($client, 'GET', "/api/plannings/{$s['planningId']}/generation-preflight", token: $s['creator']);
        self::assertCount(1, $preflight['surgicalHub'], 'The preflight still shows the suspended participant.');
    }

    /**
     * Associates admin, alice and bob, synchronised successfully at the scenario's "now".
     *
     * @param array<string, mixed> $s
     *
     * @return array<string, string> linkId by person
     */
    private function threeSynchronisedParticipants(KernelBrowser $client, array $s, int $firstSurgicalHubUserId): array
    {
        $links = [];
        foreach (['admin', 'alice', 'bob'] as $i => $who) {
            $links[$who] = $this->associate($client, $s[$who], (string) ($firstSurgicalHubUserId + $i));
            FakeSurgicalHub::setAbsences($links[$who], []);
            $this->api($client, 'POST', '/api/me/surgicalhub/sync', [], $s[$who]);
            self::assertResponseIsSuccessful();
        }

        return $links;
    }

    public function testAPartialRefreshStopsAtTheFailureAndStillClassifiesEveryParticipant(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);
        $this->prepareGeneration($s['planningId']);
        $links = $this->threeSynchronisedParticipants($client, $s, 7201);
        self::mockTime('2026-12-10 15:00:00 Europe/Brussels'); // 6 h later: recent data
        $before = \count(FakeSurgicalHub::requests());
        // The first call succeeds, the second fails at the network level.
        $calls = 0;
        FakeSurgicalHub::override(static function () use (&$calls): ?MockResponse {
            return 2 === ++$calls ? new MockResponse([new \RuntimeException('connection reset')]) : null;
        });

        $accepted = $this->requestLaunch($client, $s, $s['creator']);

        self::assertResponseStatusCodeSame(202);
        $launchCalls = \array_slice(FakeSurgicalHub::requests(), $before);
        self::assertCount(2, $launchCalls, 'One success, one failure, then no more calls.');
        foreach ($launchCalls as $call) {
            self::assertSame(4.0, $call['timeout'], 'Launch-time calls use the short timeout.');
            self::assertSame(4.0, $call['maxDuration']);
        }
        $called = array_filter($links, static fn (string $linkId): bool => [] !== array_filter($launchCalls, static fn (array $c): bool => str_contains($c['url'], $linkId)));
        self::assertCount(2, $called);
        // The failed one and the one never called: both classified by their last success (6 h old → warning).
        self::assertCount(2, $accepted['surgicalHub']['warnings']);
        foreach ($accepted['surgicalHub']['warnings'] as $warning) {
            self::assertSame('unreachable', $warning['error']);
            self::assertNotNull($warning['lastSuccessfulSyncAt']);
        }
        self::assertSame([], $accepted['surgicalHub']['overridden']);
    }

    public function testJustUnderTwentyFourHoursIsAWarningExactlyTwentyFourIsBlocking(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);
        $this->prepareGeneration($s['planningId']);
        $bobLink = $this->associate($client, $s['bob'], '7301');
        FakeSurgicalHub::setAbsences($bobLink, []);
        $this->api($client, 'POST', '/api/me/surgicalhub/sync', [], $s['bob']); // 2026-12-10 09:00
        $this->failSurgicalHubFor($bobLink);

        self::mockTime('2026-12-11 08:59:00 Europe/Brussels');
        $accepted = $this->requestLaunch($client, $s, $s['creator']);
        self::assertResponseStatusCodeSame(202);
        self::assertCount(1, $accepted['surgicalHub']['warnings']);

        // Let the queued job finish so a new launch is possible, then exactly 24 h.
        $this->runQueuedPlanningJobs();
        self::mockTime('2026-12-11 09:00:00 Europe/Brussels');
        $this->requestLaunch($client, $s, $s['creator']);
        self::assertResponseStatusCodeSame(409);
    }

    public function testRetryingWithoutTheExplicitOverrideStaysBlocked(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);
        $this->prepareGeneration($s['planningId']);
        $bobLink = $this->associate($client, $s['bob'], '7302');
        $this->failSurgicalHubFor($bobLink); // never synchronised

        foreach ([[], ['overrideStaleSurgicalHubData' => false], ['overrideStaleSurgicalHubData' => 1], []] as $body) {
            $this->requestLaunch($client, $s, $s['creator'], $body);
            self::assertResponseStatusCodeSame(409);
        }
        self::assertNull($this->api($client, 'GET', "/api/plannings/{$s['planningId']}/jobs/latest", token: $s['creator'])['job']);
        self::assertSame(0, $this->overrideEvents());
    }

    public function testTheGlobalBudgetStopsCallingAndClassifiesTheRest(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);
        $this->prepareGeneration($s['planningId']);
        $this->threeSynchronisedParticipants($client, $s, 7401);
        self::mockTime('2026-12-10 15:00:00 Europe/Brussels');
        // SurgicalHub answers, but slowly: 0.7 s a call.
        FakeSurgicalHub::override(static function (): ?MockResponse {
            usleep(700_000);

            return null;
        });
        $container = static::getContainer();
        // The same gate as in production, with a 1 s budget instead of 20 s.
        $gate = new SurgicalHubFreshnessGate(
            $container->get(PlanningTeamMemberRepository::class),
            $container->get(SurgicalHubLinkRepository::class),
            $container->get(SurgicalHubLeaveSyncService::class),
            $container->get(EntityManagerInterface::class),
            $container->get(ClockInterface::class),
            24,
            4.0,
            1.0,
        );
        $planning = $container->get(PlanningRepository::class)->findOneByStableId($s['planningId']);
        $before = \count(FakeSurgicalHub::requests());

        $report = $gate->refresh($planning);

        $calls = \count(FakeSurgicalHub::requests()) - $before;
        self::assertGreaterThanOrEqual(1, $calls);
        self::assertLessThan(3, $calls, 'Once the budget is spent, the remaining participants are not called.');
        self::assertCount(3, $report->participants, 'Everyone is still classified.');
        self::assertCount(3 - $calls, array_filter($report->participants, static fn ($p): bool => SurgicalHubSyncError::UNREACHABLE === $p->error));
    }

    public function testRemovingALeaveLeftByARevocationTouchesNeitherSnapshotNorSurgicalHub(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);
        $this->prepareGeneration($s['planningId']);
        $bobLink = $this->associate($client, $s['bob'], '7501');
        FakeSurgicalHub::setAbsences($bobLink, [['id' => 'leave', 'startDate' => '2027-01-04', 'endDate' => '2027-01-06']]);
        $manual = $this->declareRange($client, $s['bob'], '2027-02-10', '2027-02-12');
        $this->requestLaunch($client, $s, $s['creator']);
        self::assertResponseStatusCodeSame(202);
        $line = $this->runAndRead($client, $s)['lines'][0];
        $snapshotUrl = "/api/planning-generations/{$line['generationStableId']}/snapshot";
        $snapshot = $this->api($client, 'GET', $snapshotUrl, token: $s['admin']);

        // During the leave, SurgicalHub revokes the association: the leave in progress is kept (D9).
        self::mockTime('2027-01-05 09:00:00 Europe/Brussels');
        FakeSurgicalHub::markGone($bobLink, 'link_revoked');
        $this->api($client, 'POST', '/api/me/surgicalhub/sync', [], $s['bob']);
        $kept = array_values(array_filter($this->api($client, 'GET', '/api/me/calendar', token: $s['bob']), static fn (array $p): bool => 'SURGICAL_HUB' === $p['source']));
        self::assertCount(1, $kept);
        self::assertTrue($kept[0]['deletable']);

        // Only its owner can remove it.
        $this->api($client, 'DELETE', "/api/me/calendar/{$kept[0]['stableId']}", token: $s['alice']);
        self::assertResponseStatusCodeSame(404);

        $requests = \count(FakeSurgicalHub::requests());
        $this->api($client, 'DELETE', "/api/me/calendar/{$kept[0]['stableId']}", token: $s['bob']);
        self::assertResponseStatusCodeSame(204);

        self::assertCount($requests, FakeSurgicalHub::requests(), 'Removing it never calls SurgicalHub.');
        $calendar = $this->api($client, 'GET', '/api/me/calendar', token: $s['bob']);
        self::assertSame([$manual['stableId']], array_column($calendar, 'stableId'), 'The manual entry is untouched.');
        self::assertSame($snapshot['members'], $this->api($client, 'GET', $snapshotUrl, token: $s['admin'])['members'], 'The snapshot keeps its copy.');
    }

    private function overrideEvents(): int
    {
        return (int) $this->connection()->fetchOne("SELECT COUNT(*) FROM surgical_hub_link_events WHERE kind = 'STALE_DATA_OVERRIDDEN'");
    }

    private function connection(): \Doctrine\DBAL\Connection
    {
        return static::getContainer()->get(EntityManagerInterface::class)->getConnection();
    }
}
