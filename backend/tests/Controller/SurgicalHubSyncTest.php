<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Tests\AuthenticationTestHelpers;
use App\Tests\FakeSurgicalHub;
use App\Tests\SurgicalHubTestHelpers;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * SurgicalHub leave in the MedVue calendar (docs/surgicalhub-integration.md §7,
 * §9, docs/decisions.md D183), against tests/FakeSurgicalHub.php.
 */
final class SurgicalHubSyncTest extends WebTestCase
{
    use AuthenticationTestHelpers;
    use SurgicalHubTestHelpers;

    protected function setUp(): void
    {
        FakeSurgicalHub::reset();
    }

    protected function tearDown(): void
    {
        FakeSurgicalHub::reset();
        parent::tearDown();
    }

    private static function day(int $offset): string
    {
        return (new \DateTimeImmutable('today', new \DateTimeZone('Europe/Brussels')))->modify(sprintf('%+d days', $offset))->format('Y-m-d');
    }

    /** Local midnight of $date, as the API writes it (UTC, ATOM). */
    private static function midnight(string $date): string
    {
        return (new \DateTimeImmutable($date.' 00:00:00', new \DateTimeZone('Europe/Brussels')))->setTimezone(new \DateTimeZone('UTC'))->format(\DATE_ATOM);
    }

    /** @return array<string, mixed> */
    private function sync(KernelBrowser $client, string $token): array
    {
        $client->request('POST', '/api/me/surgicalhub/sync', server: $this->userHeaders($token));
        self::assertResponseIsSuccessful();

        return json_decode((string) $client->getResponse()->getContent(), true);
    }

    /** @return list<array<string, mixed>> */
    private function calendar(KernelBrowser $client, string $token): array
    {
        $client->request('GET', '/api/me/calendar', server: $this->userHeaders($token));
        self::assertResponseIsSuccessful();

        return json_decode((string) $client->getResponse()->getContent(), true);
    }

    /** @return list<array<string, mixed>> */
    private function imported(KernelBrowser $client, string $token): array
    {
        return array_values(array_filter($this->calendar($client, $token), static fn (array $p): bool => 'SURGICAL_HUB' === $p['source']));
    }

    /** @return array<string, mixed> */
    private function manual(KernelBrowser $client, string $token, string $from, string $toExclusive, string $type = 'UNAVAILABLE'): array
    {
        $client->request('POST', '/api/me/calendar', server: $this->userHeaders($token), content: json_encode([
            'type' => $type, 'startsAt' => self::midnight($from), 'endsAt' => self::midnight($toExclusive),
        ]));

        return ['status' => $client->getResponse()->getStatusCode()] + (json_decode((string) $client->getResponse()->getContent(), true) ?? []);
    }

    public function testConfirmedLeaveBecomesAReadOnlyWholeDayUnavailability(): void
    {
        $client = static::createClient();
        [$token, $linkId] = $this->linkedUser($client, 'sync.import@example.com', '100');
        FakeSurgicalHub::setAbsences($linkId, [['id' => '8120', 'startDate' => self::day(20), 'endDate' => self::day(24)]]);

        $result = $this->sync($client, $token);

        self::assertSame(['status' => 'SYNCED', 'error' => null, 'created' => 1, 'updated' => 0, 'removed' => 0], $result['outcome']);
        self::assertNotNull($result['link']['lastSuccessfulSyncAt']);
        $imported = $this->imported($client, $token);
        self::assertCount(1, $imported);
        self::assertSame('UNAVAILABLE', $imported[0]['type']);
        self::assertFalse($imported[0]['editable']);
        self::assertSame(self::midnight(self::day(20)), $imported[0]['startsAt']);
        self::assertSame(self::midnight(self::day(25)), $imported[0]['endsAt']);

        // MedVue asked for exactly the D5 window, with its own secret, and only read.
        $request = FakeSurgicalHub::requests()[0];
        self::assertSame('GET', $request['method']);
        parse_str((string) parse_url($request['url'], \PHP_URL_QUERY), $query);
        self::assertSame(self::day(-90), $query['from']);
        self::assertSame((new \DateTimeImmutable(self::day(0)))->modify('+24 months')->format('Y-m-d'), $query['to']);
        self::assertContains('Authorization: Bearer '.FakeSurgicalHub::TOKEN, $request['headers']);
    }

    public function testTwoIdenticalSynchronisationsWriteNothingTheSecondTime(): void
    {
        $client = static::createClient();
        [$token, $linkId] = $this->linkedUser($client, 'sync.idem@example.com', '101');
        FakeSurgicalHub::setAbsences($linkId, [['id' => '1', 'startDate' => self::day(5), 'endDate' => self::day(6)]]);
        $this->sync($client, $token);
        $before = $this->imported($client, $token);

        $result = $this->sync($client, $token);

        self::assertSame(0, $result['outcome']['created'] + $result['outcome']['updated'] + $result['outcome']['removed']);
        self::assertSame($before, $this->imported($client, $token));
        self::assertSame(1, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM surgical_hub_imported_leaves'));
    }

    public function testModifiedLeaveMovesTheSamePeriod(): void
    {
        $client = static::createClient();
        [$token, $linkId] = $this->linkedUser($client, 'sync.move@example.com', '102');
        FakeSurgicalHub::setAbsences($linkId, [['id' => '1', 'startDate' => self::day(5), 'endDate' => self::day(6)]]);
        $this->sync($client, $token);
        $stableId = $this->imported($client, $token)[0]['stableId'];

        FakeSurgicalHub::setAbsences($linkId, [['id' => '1', 'startDate' => self::day(7), 'endDate' => self::day(10)]]);
        $result = $this->sync($client, $token);

        self::assertSame(1, $result['outcome']['updated']);
        $imported = $this->imported($client, $token);
        self::assertCount(1, $imported);
        self::assertSame($stableId, $imported[0]['stableId']);
        self::assertSame(self::midnight(self::day(7)), $imported[0]['startsAt']);
        self::assertSame(self::midnight(self::day(11)), $imported[0]['endsAt']);
    }

    public function testASplitInSurgicalHubShrinksOneAndCreatesTheOther(): void
    {
        $client = static::createClient();
        [$token, $linkId] = $this->linkedUser($client, 'sync.split@example.com', '103');
        FakeSurgicalHub::setAbsences($linkId, [['id' => '1', 'startDate' => self::day(10), 'endDate' => self::day(14)]]);
        $this->sync($client, $token);

        // SurgicalHub "retirer un jour" on day 12: the absence shrinks, a new one carries the rest.
        FakeSurgicalHub::setAbsences($linkId, [
            ['id' => '1', 'startDate' => self::day(10), 'endDate' => self::day(11)],
            ['id' => '2', 'startDate' => self::day(13), 'endDate' => self::day(14)],
        ]);
        $result = $this->sync($client, $token);

        self::assertSame(['status' => 'SYNCED', 'error' => null, 'created' => 1, 'updated' => 1, 'removed' => 0], $result['outcome']);
        self::assertCount(2, $this->imported($client, $token));
    }

    public function testDeletedLeaveIsRemovedButNeverAManualPeriod(): void
    {
        $client = static::createClient();
        [$token, $linkId] = $this->linkedUser($client, 'sync.delete@example.com', '104');
        FakeSurgicalHub::setAbsences($linkId, [['id' => '1', 'startDate' => self::day(10), 'endDate' => self::day(14)]]);
        $this->sync($client, $token);
        // A manual entry on the very same days.
        self::assertSame(201, $this->manual($client, $token, self::day(10), self::day(15))['status']);

        FakeSurgicalHub::setAbsences($linkId, []);
        $result = $this->sync($client, $token);

        self::assertSame(1, $result['outcome']['removed']);
        $calendar = $this->calendar($client, $token);
        self::assertCount(1, $calendar);
        self::assertSame('MANUAL', $calendar[0]['source']);
    }

    public function testOnlyConfirmedLeaveExcludesAndALeaveNoLongerConfirmedIsRemoved(): void
    {
        $client = static::createClient();
        [$token, $linkId] = $this->linkedUser($client, 'sync.status@example.com', '105');
        FakeSurgicalHub::setAbsences($linkId, [
            ['id' => '1', 'startDate' => self::day(3), 'endDate' => self::day(4)],
            ['id' => '2', 'startDate' => self::day(8), 'endDate' => self::day(9), 'status' => 'PENDING'],
        ]);
        $this->sync($client, $token);
        self::assertCount(1, $this->imported($client, $token));

        FakeSurgicalHub::setAbsences($linkId, [['id' => '1', 'startDate' => self::day(3), 'endDate' => self::day(4), 'status' => 'REFUSED']]);
        $result = $this->sync($client, $token);

        self::assertSame(1, $result['outcome']['removed']);
        self::assertCount(0, $this->imported($client, $token));
    }

    public function testManualAndImportedPeriodsMayOverlapButManualOnesStillMayNot(): void
    {
        $client = static::createClient();
        [$token, $linkId] = $this->linkedUser($client, 'sync.overlap@example.com', '106');
        self::assertSame(201, $this->manual($client, $token, self::day(10), self::day(16))['status']);
        FakeSurgicalHub::setAbsences($linkId, [['id' => '1', 'startDate' => self::day(12), 'endDate' => self::day(20)]]);

        self::assertSame('SYNCED', $this->sync($client, $token)['outcome']['status']);

        // A new manual entry touching the imported one is fine; touching a manual one is not.
        self::assertSame(201, $this->manual($client, $token, self::day(21), self::day(23))['status']);
        self::assertSame(409, $this->manual($client, $token, self::day(16), self::day(18))['status']);
        // A preference may sit on imported days, as on manual ones (the hard signal dominates).
        self::assertSame(201, $this->manual($client, $token, self::day(13), self::day(14), 'PREFER_DUTY')['status']);
        self::assertCount(4, $this->calendar($client, $token));
    }

    public function testAnImportedPeriodCannotBeEditedOrDeletedInMedVue(): void
    {
        $client = static::createClient();
        [$token, $linkId] = $this->linkedUser($client, 'sync.readonly@example.com', '107');
        FakeSurgicalHub::setAbsences($linkId, [['id' => '1', 'startDate' => self::day(10), 'endDate' => self::day(12)]]);
        $this->sync($client, $token);
        $period = $this->imported($client, $token)[0];

        $client->request('PATCH', '/api/me/calendar/'.$period['stableId'], server: $this->userHeaders($token), content: json_encode([
            'type' => 'UNAVAILABLE', 'startsAt' => self::midnight(self::day(10)), 'endsAt' => self::midnight(self::day(11)),
        ]));
        self::assertResponseStatusCodeSame(409);
        self::assertSame('imported_period_read_only', json_decode((string) $client->getResponse()->getContent(), true)['error']);

        $client->request('DELETE', '/api/me/calendar/'.$period['stableId'], server: $this->userHeaders($token));
        self::assertResponseStatusCodeSame(409);

        self::assertSame([$period], $this->imported($client, $token));
    }

    /**
     * @return iterable<string, array{0: callable(string): ?MockResponse, 1: string}>
     */
    public static function failures(): iterable
    {
        $json = static fn (array $data, int $status = 200): MockResponse => FakeSurgicalHub::json($data, $status);

        yield 'server error' => [static fn (string $linkId): MockResponse => new MockResponse('oops', ['http_code' => 500]), 'server_error'];
        yield 'proxy HTML 404' => [static fn (string $linkId): MockResponse => new MockResponse('<html>404</html>', ['http_code' => 404]), 'invalid_response'];
        yield '404 without apiVersion' => [static fn (string $linkId): MockResponse => $json(['error' => 'link_not_found'], 404), 'invalid_response'];
        yield '410 with another code' => [static fn (string $linkId): MockResponse => $json(['apiVersion' => 1, 'error' => 'gone'], 410), 'invalid_response'];
        yield 'unauthorized' => [static fn (string $linkId): MockResponse => $json(['apiVersion' => 1, 'error' => 'unauthorized'], 401), 'unauthorized'];
        yield 'rate limited' => [static fn (string $linkId): MockResponse => $json(['apiVersion' => 1, 'error' => 'rate_limited'], 429), 'rate_limited'];
        yield 'window too large' => [static fn (string $linkId): MockResponse => $json(['apiVersion' => 1, 'error' => 'window_too_large'], 422), 'window_too_large'];
        yield 'redirect' => [static fn (string $linkId): MockResponse => new MockResponse('', ['http_code' => 302, 'response_headers' => ['location: https://elsewhere.test/']]), 'unreachable'];
        yield 'network error' => [static fn (string $linkId): MockResponse => new MockResponse([new \RuntimeException('timeout')]), 'unreachable'];
        yield 'not JSON' => [static fn (string $linkId): MockResponse => new MockResponse('<html>ok</html>', ['http_code' => 200]), 'invalid_response'];
        yield 'empty and not complete' => [static fn (string $linkId): MockResponse => $json(self::body($linkId, [], complete: false)), 'invalid_response'];
        yield 'complete missing' => [static fn (string $linkId): MockResponse => $json(array_diff_key(self::body($linkId, []), ['complete' => true])), 'invalid_response'];
        yield 'another window' => [static fn (string $linkId): MockResponse => $json(['window' => ['from' => '2020-01-01', 'to' => '2020-12-31']] + self::body($linkId, [])), 'invalid_response'];
        yield 'another link' => [static fn (string $linkId): MockResponse => $json(['linkId' => 'someone-else'] + self::body($linkId, [])), 'invalid_response'];
        yield 'another api version' => [static fn (string $linkId): MockResponse => $json(['apiVersion' => 2] + self::body($linkId, [])), 'invalid_response'];
        yield 'duplicate ids' => [static fn (string $linkId): MockResponse => $json(self::body($linkId, [self::absence('9', 1, 2), self::absence('9', 3, 4)])), 'invalid_response'];
        yield 'end before start' => [static fn (string $linkId): MockResponse => $json(self::body($linkId, [self::absence('9', 4, 2)])), 'invalid_response'];
        yield 'impossible date' => [static fn (string $linkId): MockResponse => $json(self::body($linkId, [['id' => '9', 'startDate' => '2026-02-31', 'endDate' => '2026-03-01', 'status' => 'CONFIRMED']])), 'invalid_response'];
        yield 'outside the window' => [static fn (string $linkId): MockResponse => $json(self::body($linkId, [self::absence('9', -400, -399)])), 'invalid_response'];
        yield 'absences not a list' => [static fn (string $linkId): MockResponse => $json(['absences' => ['a' => self::absence('9', 1, 2)]] + self::body($linkId, [])), 'invalid_response'];
    }

    /**
     * @param callable(string): ?MockResponse $answer
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('failures')]
    public function testAnyFailureLeavesEveryLocalPeriodUntouched(callable $answer, string $expectedError): void
    {
        $client = static::createClient();
        [$token, $linkId] = $this->linkedUser($client, 'sync.fail.'.md5($expectedError.random_int(0, \PHP_INT_MAX)).'@example.com', (string) random_int(1000, 999999));
        FakeSurgicalHub::setAbsences($linkId, [['id' => '1', 'startDate' => self::day(10), 'endDate' => self::day(12)]]);
        $first = $this->sync($client, $token);
        self::assertSame(201, $this->manual($client, $token, self::day(30), self::day(31))['status']);
        $before = $this->calendar($client, $token);

        FakeSurgicalHub::override(static fn (string $method, string $url): ?MockResponse => 'GET' === $method ? $answer($linkId) : null);
        $result = $this->sync($client, $token);

        self::assertSame('FAILED', $result['outcome']['status']);
        self::assertSame($expectedError, $result['outcome']['error']);
        self::assertSame($expectedError, $result['link']['lastSyncError']);
        self::assertSame('ACTIVE', $result['link']['status']);
        self::assertSame($first['link']['lastSuccessfulSyncAt'], $result['link']['lastSuccessfulSyncAt']);
        self::assertSame($before, $this->calendar($client, $token));
    }

    /**
     * @param list<array<string, mixed>> $absences
     *
     * @return array<string, mixed>
     */
    private static function body(string $linkId, array $absences, bool $complete = true): array
    {
        return [
            'apiVersion' => 1,
            'linkId' => $linkId,
            'window' => ['from' => self::day(-90), 'to' => (new \DateTimeImmutable(self::day(0)))->modify('+24 months')->format('Y-m-d')],
            'generatedAt' => (new \DateTimeImmutable())->format(\DATE_ATOM),
            'complete' => $complete,
            'absences' => $absences,
        ];
    }

    /** @return array<string, mixed> */
    private static function absence(string $id, int $start, int $end): array
    {
        return ['id' => $id, 'startDate' => self::day($start), 'endDate' => self::day($end), 'status' => 'CONFIRMED', 'updatedAt' => null];
    }

    public function testAnImportThatLeftTheWindowIsKept(): void
    {
        $client = static::createClient();
        [$token, $linkId] = $this->linkedUser($client, 'sync.window@example.com', '108');
        FakeSurgicalHub::setAbsences($linkId, [['id' => '1', 'startDate' => self::day(10), 'endDate' => self::day(12)]]);
        $this->sync($client, $token);
        // Time passes: that absence now lies 200 days in the past, outside the window.
        $this->connection()->executeStatement(
            "UPDATE surgical_hub_imported_leaves SET start_date = start_date - 210, end_date = end_date - 210;
             UPDATE user_availability_periods SET starts_at = starts_at - INTERVAL '210 days', ends_at = ends_at - INTERVAL '210 days' WHERE source = 'SURGICAL_HUB'",
        );
        FakeSurgicalHub::setAbsences($linkId, []);

        $result = $this->sync($client, $token);

        self::assertSame(0, $result['outcome']['removed']);
        self::assertCount(1, $this->imported($client, $token));
    }

    public function testSurgicalHubRevocationRemovesFutureLeaveAndKeepsThePastAndManualEntries(): void
    {
        $client = static::createClient();
        [$token, $linkId] = $this->linkedUser($client, 'sync.revoked@example.com', '109');
        FakeSurgicalHub::setAbsences($linkId, [
            ['id' => 'past', 'startDate' => self::day(-20), 'endDate' => self::day(-18)],
            ['id' => 'ongoing', 'startDate' => self::day(-2), 'endDate' => self::day(3)],
            ['id' => 'today', 'startDate' => self::day(0), 'endDate' => self::day(0)],
            ['id' => 'future', 'startDate' => self::day(15), 'endDate' => self::day(16)],
        ]);
        $this->sync($client, $token);
        self::assertSame(201, $this->manual($client, $token, self::day(15), self::day(17))['status']);

        FakeSurgicalHub::markGone($linkId, 'link_revoked');
        $result = $this->sync($client, $token);

        self::assertSame('REVOKED', $result['outcome']['status']);
        self::assertSame(2, $result['outcome']['removed'], 'today and future were removed: the count says so.');
        self::assertSame('REVOKED_REMOTE', $result['link']['status']);
        $kept = array_map(static fn (array $p): string => $p['startsAt'], $this->imported($client, $token));
        sort($kept);
        self::assertSame([self::midnight(self::day(-20)), self::midnight(self::day(-2))], $kept);
        self::assertCount(1, array_filter($this->calendar($client, $token), static fn (array $p): bool => 'MANUAL' === $p['source']));

        // Revoked: no more reading, from the page or the cron.
        $requestsSoFar = \count(FakeSurgicalHub::requests());
        $client->request('POST', '/api/me/surgicalhub/sync', server: $this->userHeaders($token));
        self::assertResponseStatusCodeSame(404);
        self::assertCount($requestsSoFar, FakeSurgicalHub::requests());
    }

    public function testAnUnknownLinkOnlySuspendsAndDeletesNothing(): void
    {
        $client = static::createClient();
        [$token, $linkId] = $this->linkedUser($client, 'sync.notfound@example.com', '110');
        FakeSurgicalHub::setAbsences($linkId, [
            ['id' => 'ongoing', 'startDate' => self::day(-2), 'endDate' => self::day(3)],
            ['id' => 'future', 'startDate' => self::day(15), 'endDate' => self::day(16)],
        ]);
        $this->sync($client, $token);
        $before = $this->imported($client, $token);

        // SurgicalHub restored from a backup older than the association: it answers 404.
        FakeSurgicalHub::markGone($linkId, 'link_not_found');
        $result = $this->sync($client, $token);

        self::assertSame('SUSPENDED', $result['outcome']['status']);
        self::assertSame(0, $result['outcome']['removed']);
        self::assertSame('SUSPENDED', $result['link']['status']);
        self::assertNotNull($result['link']['suspendedAt']);
        self::assertSame($before, $this->imported($client, $token), 'A 404 never deletes anything.');

        // Suspended: nothing is read any more, the imports stay read-only.
        $requests = \count(FakeSurgicalHub::requests());
        $client->request('POST', '/api/me/surgicalhub/sync', server: $this->userHeaders($token));
        self::assertResponseStatusCodeSame(409);
        self::assertSame('link_suspended', json_decode((string) $client->getResponse()->getContent(), true)['error']);
        self::assertCount($requests, FakeSurgicalHub::requests());
        $client->request('DELETE', '/api/me/calendar/'.$before[0]['stableId'], server: $this->userHeaders($token));
        self::assertResponseStatusCodeSame(409);
    }

    public function testANewCodeForTheSamePairResumesASuspendedAssociationWithItsImportsIntact(): void
    {
        $client = static::createClient();
        [$token, $linkId] = $this->linkedUser($client, 'sync.resume@example.com', '120');
        FakeSurgicalHub::setAbsences($linkId, [['id' => 'future', 'startDate' => self::day(15), 'endDate' => self::day(16)]]);
        $this->sync($client, $token);
        $before = $this->imported($client, $token);
        FakeSurgicalHub::markGone($linkId, 'link_not_found');
        $this->sync($client, $token);

        // The owner types a new code in SurgicalHub: same pair, same linkId, reading resumes.
        $this->redeemLinkCode($client, $this->issueLinkCode($client, $token), '120');
        self::assertResponseIsSuccessful();
        self::assertSame($linkId, json_decode((string) $client->getResponse()->getContent(), true)['linkId']);
        FakeSurgicalHub::reset();
        FakeSurgicalHub::setAbsences($linkId, [['id' => 'future', 'startDate' => self::day(15), 'endDate' => self::day(16)]]);

        $result = $this->sync($client, $token);

        self::assertSame(['status' => 'SYNCED', 'error' => null, 'created' => 0, 'updated' => 0, 'removed' => 0], $result['outcome']);
        self::assertSame($before, $this->imported($client, $token));
        self::assertSame(1, (int) $this->connection()->fetchOne("SELECT COUNT(*) FROM surgical_hub_link_events WHERE kind = 'LINK_RESUMED'"));
    }

    public function testASuspendedAssociationStillHoldsBothAccountsUntilTheOwnerDissociates(): void
    {
        $client = static::createClient();
        [$token, $linkId] = $this->linkedUser($client, 'sync.hold@example.com', '121');
        FakeSurgicalHub::setAbsences($linkId, [
            ['id' => 'past', 'startDate' => self::day(-10), 'endDate' => self::day(-9)],
            ['id' => 'future', 'startDate' => self::day(15), 'endDate' => self::day(16)],
        ]);
        $this->sync($client, $token);
        FakeSurgicalHub::markGone($linkId, 'link_not_found');
        $this->sync($client, $token);

        // Another SurgicalHub account cannot be associated while this one is suspended.
        $this->redeemLinkCode($client, $this->issueLinkCode($client, $token), '122');
        self::assertResponseStatusCodeSame(409);

        // Dissociating is the owner's way out: D9 applies then, never before.
        $client->request('DELETE', '/api/me/surgicalhub/link', server: $this->userHeaders($token));
        self::assertResponseStatusCodeSame(204);
        self::assertCount(1, $this->imported($client, $token));
    }

    public function testAnImportLeftByARevocationCanBeRemovedByItsOwnerButNeverEdited(): void
    {
        $client = static::createClient();
        [$token, $linkId] = $this->linkedUser($client, 'sync.orphan@example.com', '123');
        FakeSurgicalHub::setAbsences($linkId, [['id' => 'ongoing', 'startDate' => self::day(-3), 'endDate' => self::day(60)]]);
        $this->sync($client, $token);
        $period = $this->imported($client, $token)[0];
        self::assertFalse($period['deletable'], 'Still synchronised: only SurgicalHub changes it.');

        FakeSurgicalHub::markGone($linkId, 'link_revoked');
        $this->sync($client, $token);
        $kept = $this->imported($client, $token)[0];
        self::assertSame($period['stableId'], $kept['stableId'], 'D9 keeps the leave in progress.');
        self::assertTrue($kept['deletable']);
        self::assertFalse($kept['editable']);

        $client->request('PATCH', '/api/me/calendar/'.$kept['stableId'], server: $this->userHeaders($token), content: json_encode([
            'type' => 'UNAVAILABLE', 'startsAt' => self::midnight(self::day(-3)), 'endsAt' => self::midnight(self::day(5)),
        ]));
        self::assertResponseStatusCodeSame(409);

        $client->request('DELETE', '/api/me/calendar/'.$kept['stableId'], server: $this->userHeaders($token));
        self::assertResponseStatusCodeSame(204);
        self::assertSame([], $this->imported($client, $token));
        self::assertSame(0, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM surgical_hub_imported_leaves'));
    }

    public function testThePeriodicCommandStopsAndAlertsWhenSurgicalHubForgetsSeveralAssociations(): void
    {
        $client = static::createClient();
        $links = [];
        foreach (['a', 'b', 'c'] as $i => $name) {
            [$token, $linkId] = $this->linkedUser($client, "sync.restore.$name@example.com", (string) (130 + $i));
            FakeSurgicalHub::setAbsences($linkId, [['id' => 'future', 'startDate' => self::day(15), 'endDate' => self::day(16)]]);
            $this->sync($client, $token);
            $links[] = [$token, $linkId];
        }
        // SurgicalHub restored from an older backup: none of the three is known any more.
        foreach ($links as [, $linkId]) {
            FakeSurgicalHub::markGone($linkId, 'link_not_found');
        }
        $tester = new CommandTester((new Application(self::$kernel))->find('app:surgicalhub:sync'));

        self::assertSame(1, $tester->execute([]));
        self::assertStringContainsString('possibly a restored backup', (string) preg_replace('/\s+/', ' ', $tester->getDisplay()));
        self::assertSame(2, (int) $this->connection()->fetchOne("SELECT COUNT(*) FROM surgical_hub_links WHERE status = 'SUSPENDED'"), 'The pass stops at the second one.');
        foreach ($links as [$token]) {
            self::assertCount(1, $this->imported($client, $token), 'Nothing is ever deleted.');
        }

        // Next passes call nothing and keep alerting until someone checks.
        $requests = \count(FakeSurgicalHub::requests());
        self::assertSame(1, $tester->execute([]));
        self::assertStringContainsString('synchronisation halted', (string) preg_replace('/\s+/', ' ', $tester->getDisplay()));
        self::assertCount($requests, FakeSurgicalHub::requests());
    }

    public function testOwnerDissociatingRemovesFutureLeaveAndTellsSurgicalHub(): void
    {
        $client = static::createClient();
        [$token, $linkId] = $this->linkedUser($client, 'sync.unlink@example.com', '111');
        FakeSurgicalHub::setAbsences($linkId, [
            ['id' => 'past', 'startDate' => self::day(-5), 'endDate' => self::day(-4)],
            ['id' => 'future', 'startDate' => self::day(5), 'endDate' => self::day(6)],
        ]);
        $this->sync($client, $token);

        $client->request('DELETE', '/api/me/surgicalhub/link', server: $this->userHeaders($token));
        self::assertResponseStatusCodeSame(204);

        self::assertCount(1, $this->imported($client, $token));
        self::assertSame(self::midnight(self::day(-5)), $this->imported($client, $token)[0]['startsAt']);
        $last = FakeSurgicalHub::requests()[\count(FakeSurgicalHub::requests()) - 1];
        self::assertSame('DELETE', $last['method']);
        self::assertStringEndsWith('/api/integrations/medvue/v1/links/'.$linkId, $last['url']);
        self::assertSame('', $last['body']);
    }

    public function testDissociationStandsEvenIfSurgicalHubCannotBeTold(): void
    {
        $client = static::createClient();
        [$token] = $this->linkedUser($client, 'sync.unlinkdown@example.com', '112');
        FakeSurgicalHub::override(static fn (): MockResponse => new MockResponse('down', ['http_code' => 503]));

        $client->request('DELETE', '/api/me/surgicalhub/link', server: $this->userHeaders($token));

        self::assertResponseStatusCodeSame(204);
        $client->request('GET', '/api/me/surgicalhub', server: $this->userHeaders($token));
        self::assertSame('REVOKED_LOCAL', json_decode((string) $client->getResponse()->getContent(), true)['link']['status']);
    }

    public function testANewAssociationOfTheSamePairTakesThePastImportsOverWithoutDuplicates(): void
    {
        $client = static::createClient();
        [$token, $firstLinkId] = $this->linkedUser($client, 'sync.relink@example.com', '113');
        $past = ['id' => 'past', 'startDate' => self::day(-5), 'endDate' => self::day(-4)];
        FakeSurgicalHub::setAbsences($firstLinkId, [$past]);
        $this->sync($client, $token);
        $client->request('DELETE', '/api/me/surgicalhub/link', server: $this->userHeaders($token));

        $this->redeemLinkCode($client, $this->issueLinkCode($client, $token), '113');
        $secondLinkId = json_decode((string) $client->getResponse()->getContent(), true)['linkId'];
        self::assertNotSame($firstLinkId, $secondLinkId);
        FakeSurgicalHub::setAbsences($secondLinkId, [$past, ['id' => 'next', 'startDate' => self::day(8), 'endDate' => self::day(8)]]);

        $result = $this->sync($client, $token);

        self::assertSame(1, $result['outcome']['created']);
        self::assertCount(2, $this->imported($client, $token));
    }

    public function testSynchronisationNotesOpenCollectionsButNeverAnswersThem(): void
    {
        $client = static::createClient();
        [$token, $linkId] = $this->linkedUser($client, 'sync.collection@example.com', '114');
        // A planning they take part in opens a collection over its whole period.
        $client->request('POST', '/api/plannings', server: $this->userHeaders($token), content: json_encode([
            'name' => 'Gardes', 'startsAt' => self::day(1), 'endsAt' => self::day(60), 'timezone' => 'Europe/Brussels',
            'primaryTeam' => ['name' => 'Seniors'], 'includeMe' => true,
        ]));
        self::assertResponseStatusCodeSame(201);
        $responseRow = static fn (self $test): array => $test->connection()->fetchAssociative(
            "SELECT r.acknowledged_at, r.last_availability_change_at FROM availability_collection_responses r JOIN users u ON u.id = r.user_id WHERE u.email = 'sync.collection@example.com'",
        );
        self::assertNull($responseRow($this)['last_availability_change_at']);
        FakeSurgicalHub::setAbsences($linkId, [['id' => '1', 'startDate' => self::day(10), 'endDate' => self::day(12)]]);

        $this->sync($client, $token);

        $response = $responseRow($this);
        self::assertNull($response['acknowledged_at'], 'An import is never an answer.');
        self::assertNotNull($response['last_availability_change_at']);
    }

    public function testThePeriodicCommandSynchronisesEveryActiveAssociationAndOnlyReads(): void
    {
        $client = static::createClient();
        [$tokenA, $linkA] = $this->linkedUser($client, 'sync.cron.a@example.com', '115');
        [$tokenB, $linkB] = $this->linkedUser($client, 'sync.cron.b@example.com', '116');
        FakeSurgicalHub::setAbsences($linkA, [['id' => '1', 'startDate' => self::day(3), 'endDate' => self::day(3)]]);
        FakeSurgicalHub::override(static fn (string $method, string $url): ?MockResponse => str_contains($url, $linkB) ? new MockResponse('down', ['http_code' => 503]) : null);

        $tester = new CommandTester((new Application(self::$kernel))->find('app:surgicalhub:sync'));
        $exit = $tester->execute([]);

        self::assertSame(1, $exit, 'One association failed: the run reports it.');
        self::assertStringContainsString('server_error', $tester->getDisplay());
        self::assertCount(1, $this->imported($client, $tokenA));
        self::assertCount(0, $this->imported($client, $tokenB));
        foreach (FakeSurgicalHub::requests() as $request) {
            self::assertSame('GET', $request['method']);
            self::assertMatchesRegularExpression('#^https://surgicalhub\.test/api/integrations/medvue/v1/links/[^/]+/absences\?#', $request['url']);
        }
    }

    public function testOneSuspendedAssociationIsSkippedWithoutStoppingTheOthersAndIsNeverARevocation(): void
    {
        $client = static::createClient();
        [$suspendedToken, $suspendedLink] = $this->linkedUser($client, 'sync.skip.a@example.com', '140');
        [$activeToken, $activeLink] = $this->linkedUser($client, 'sync.skip.b@example.com', '141');
        FakeSurgicalHub::setAbsences($suspendedLink, [['id' => 'future', 'startDate' => self::day(15), 'endDate' => self::day(16)]]);
        FakeSurgicalHub::setAbsences($activeLink, [['id' => 'future', 'startDate' => self::day(20), 'endDate' => self::day(21)]]);
        $this->sync($client, $suspendedToken);
        FakeSurgicalHub::markGone($suspendedLink, 'link_not_found');
        $this->sync($client, $suspendedToken);
        $requests = \count(FakeSurgicalHub::requests());

        $tester = new CommandTester((new Application(self::$kernel))->find('app:surgicalhub:sync'));

        self::assertSame(0, $tester->execute([]), 'One suspended association alone does not halt the synchronisation.');
        $calls = \array_slice(FakeSurgicalHub::requests(), $requests);
        self::assertCount(1, $calls);
        self::assertStringContainsString($activeLink, $calls[0]['url'], 'Only the active association is read.');
        self::assertSame(10.0, $calls[0]['timeout'], 'The periodic synchronisation keeps its own timeout.');
        self::assertSame(15.0, $calls[0]['maxDuration']);
        self::assertStringNotContainsString(FakeSurgicalHub::TOKEN, $tester->getDisplay());
        self::assertStringNotContainsString(self::SURGICALHUB_INBOUND_SECRET, $tester->getDisplay());
        self::assertCount(1, $this->imported($client, $suspendedToken), 'Suspended: nothing deleted.');
        self::assertCount(1, $this->imported($client, $activeToken));
        self::assertSame(0, (int) $this->connection()->fetchOne("SELECT COUNT(*) FROM surgical_hub_link_events WHERE kind IN ('UNLINKED_REMOTE', 'UNLINKED_LOCAL')"), 'A suspension is never recorded as a revocation.');
        self::assertSame(0, (int) $this->connection()->fetchOne("SELECT COUNT(*) FROM surgical_hub_links WHERE status IN ('REVOKED_REMOTE', 'REVOKED_LOCAL')"));
    }

    public function testAnImportNeverChangesAPreferenceOfThePersonsOwn(): void
    {
        $client = static::createClient();
        [$token, $linkId] = $this->linkedUser($client, 'sync.pref@example.com', '142');
        $preference = $this->manual($client, $token, self::day(10), self::day(13), 'PREFER_DUTY');
        self::assertSame(201, $preference['status']);
        FakeSurgicalHub::setAbsences($linkId, [['id' => '1', 'startDate' => self::day(9), 'endDate' => self::day(14)]]);

        $this->sync($client, $token);
        FakeSurgicalHub::setAbsences($linkId, []);
        $this->sync($client, $token);

        $stored = array_values(array_filter($this->calendar($client, $token), static fn (array $p): bool => $p['stableId'] === $preference['stableId']));
        self::assertCount(1, $stored);
        self::assertSame([$preference['startsAt'], $preference['endsAt'], $preference['updatedAt']], [$stored[0]['startsAt'], $stored[0]['endsAt'], $stored[0]['updatedAt']]);
    }

    public function testLeaveKeptByARevocationIsSynchronisedAgainAsSoonAsTheSamePairIsAssociated(): void
    {
        $client = static::createClient();
        [$token, $firstLinkId] = $this->linkedUser($client, 'sync.repair@example.com', '143');
        FakeSurgicalHub::setAbsences($firstLinkId, [['id' => 'past', 'startDate' => self::day(-5), 'endDate' => self::day(-4)]]);
        $this->sync($client, $token);
        $client->request('DELETE', '/api/me/surgicalhub/link', server: $this->userHeaders($token));
        self::assertTrue($this->imported($client, $token)[0]['deletable']);

        // A new association of the same pair, not synchronised yet: the kept leave is SurgicalHub's again.
        $this->redeemLinkCode($client, $this->issueLinkCode($client, $token), '143');
        self::assertResponseIsSuccessful();
        $period = $this->imported($client, $token)[0];

        self::assertFalse($period['deletable']);
        $client->request('DELETE', '/api/me/calendar/'.$period['stableId'], server: $this->userHeaders($token));
        self::assertResponseStatusCodeSame(409);
    }

    public function testManualSynchronisationIsForTheOwnerOnly(): void
    {
        $client = static::createClient();
        $client->request('POST', '/api/me/surgicalhub/sync');
        self::assertResponseStatusCodeSame(401);

        $this->registerUser($client, 'sync.none@example.com', 'correct-horse-battery');
        $token = $this->loginUser($client, 'sync.none@example.com', 'correct-horse-battery');
        $client->request('POST', '/api/me/surgicalhub/sync', server: $this->userHeaders($token));
        self::assertResponseStatusCodeSame(404);
        self::assertSame([], FakeSurgicalHub::requests());
    }
}
