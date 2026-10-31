<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Service\PlanningCycleAudit;
use App\Tests\CalendarWorkflowTestHelpers;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The periods of a planning, lot L2 (docs/planning-cycles.md §5.1-§5.3,
 * §5.5, §5.9, §7.3, docs/decisions.md D187), through the real endpoints.
 *
 * Fixture (pilotScenario): one planning 2027-01-01 → 2027-05-01 (period 1),
 * its primary line "Seniors" with admin/alice/bob, its first collection
 * open. The test environment enables MEDVUE_PERIOD_CREATION_ENABLED; the
 * disabled default is PlanningCycleServiceTest's.
 */
final class PlanningCycleControllerTest extends WebTestCase
{
    use CalendarWorkflowTestHelpers;

    private KernelBrowser $client;

    /** @var array<string, mixed> */
    private array $s;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->s = $this->pilotScenario($this->client);
    }

    // --- helpers ---------------------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function periods(?string $token = null): array
    {
        return $this->api($this->client, 'GET', "/api/plannings/{$this->s['planningId']}/periods", token: $token ?? $this->s['creator']);
    }

    private function lastPeriodId(): string
    {
        $periods = $this->periods()['periods'];

        return $periods[\count($periods) - 1]['stableId'];
    }

    /**
     * @return array<string, mixed>
     */
    private function createPeriod(string $endsAt, ?string $previous = null, ?string $token = null): array
    {
        return $this->api($this->client, 'POST', "/api/plannings/{$this->s['planningId']}/periods", [
            'endsAt' => $endsAt,
            'previousPeriodStableId' => $previous ?? $this->lastPeriodId(),
        ], $token ?? $this->s['creator']);
    }

    private function db(): Connection
    {
        return static::getContainer()->get(Connection::class);
    }

    private function cycleId(string $stableId): int
    {
        return (int) $this->db()->fetchOne('SELECT id FROM planning_cycles WHERE stable_id = ?', [$stableId]);
    }

    /**
     * Everything a period holds, hashed: its row, the periods of its lines,
     * their duties, generations and assignments, its publications (and their
     * entries), collections and answers.
     */
    private function fingerprint(string $periodStableId): string
    {
        return (string) $this->db()->fetchOne(<<<'SQL'
            SELECT md5(string_agg(x, '|' ORDER BY x)) FROM (
              SELECT 'y' || ROW(cy.*)::text AS x FROM planning_cycles cy WHERE cy.id = :c
              UNION ALL SELECT 'p' || ROW(pp.*)::text FROM planning_periods pp WHERE pp.planning_cycle_id = :c
              UNION ALL SELECT 'd' || ROW(d.*)::text FROM duties d JOIN planning_periods pp ON pp.id = d.planning_period_id WHERE pp.planning_cycle_id = :c
              UNION ALL SELECT 'g' || ROW(g.*)::text FROM planning_generations g JOIN planning_periods pp ON pp.id = g.planning_period_id WHERE pp.planning_cycle_id = :c
              UNION ALL SELECT 'a' || ROW(a.*)::text FROM duty_assignments a JOIN duties d ON d.id = a.duty_id JOIN planning_periods pp ON pp.id = d.planning_period_id WHERE pp.planning_cycle_id = :c
              UNION ALL SELECT 'u' || ROW(u.*)::text FROM planning_publications u WHERE u.planning_cycle_id = :c
              UNION ALL SELECT 'e' || ROW(e.*)::text FROM planning_publication_entries e JOIN planning_publications u ON u.id = e.publication_id WHERE u.planning_cycle_id = :c
              UNION ALL SELECT 'c' || ROW(ac.*)::text FROM availability_collections ac WHERE ac.planning_cycle_id = :c
              UNION ALL SELECT 'r' || ROW(r.*)::text FROM availability_collection_responses r JOIN availability_collections ac ON ac.id = r.collection_id WHERE ac.planning_cycle_id = :c
            ) s
        SQL, ['c' => $this->cycleId($periodStableId)]);
    }

    private function publishPeriodOne(): void
    {
        $this->prepareLine($this->s['planningId'], [['2027-01-05', '2027-01-06'], ['2027-01-12', '2027-01-13']]);
        $this->generate($this->client, $this->s);
        $this->api($this->client, 'POST', "/api/plannings/{$this->s['planningId']}/publish", [], $this->s['creator']);
        self::assertResponseIsSuccessful();
    }

    private function assertError(int $status, string $code): void
    {
        self::assertResponseStatusCodeSame($status);
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame($code, $body['error'] ?? null, (string) $this->client->getResponse()->getContent());
    }

    // --- single period: what every planning has today -------------------------------------------

    public function testASinglePeriodPlanningExposesItsPeriodAndKeepsItsLegacyFields(): void
    {
        $list = $this->periods();
        self::assertResponseIsSuccessful();
        self::assertSame(1, $list['periodCount']);
        self::assertTrue($list['canCreate']);
        self::assertCount(1, $list['periods']);
        $period = $list['periods'][0];
        self::assertSame(1, $period['number']);
        self::assertSame(['2027-01-01', '2027-05-01', '2027-04-30'], [$period['startsAt'], $period['endsAt'], $period['lastDay']]);
        self::assertSame('collecting', $period['status']);
        self::assertSame('future', $period['timeState']);
        self::assertSame(1, $period['collections']['open']);
        self::assertSame(0, $period['collections']['uncoveredDays']);
        self::assertTrue($period['isLast']);
        self::assertTrue($period['canEditEnd']);
        self::assertTrue($period['canEditStart'], 'a blank first period: its start moves too (D208)');
        self::assertFalse($period['canDelete'], 'period 1 is never deleted');
        self::assertNull($list['currentPeriod']);
        self::assertSame($period['stableId'], $list['nextPeriod']['stableId']);

        $detail = $this->api($this->client, 'GET', "/api/plannings/{$this->s['planningId']}", token: $this->s['creator']);
        self::assertCount(1, $detail['periods']);
        self::assertSame($period['stableId'], $detail['nextPeriodStableId']);
        $line = $detail['lines'][0];
        self::assertSame('DRAFT', $line['periodStatus']);
        self::assertSame($period['lines'][0]['planningPeriodStableId'], $line['planningPeriodStableId']);
        self::assertSame([['periodStableId' => $period['stableId'], 'number' => 1, 'planningPeriodStableId' => $line['planningPeriodStableId'], 'status' => 'DRAFT', 'memberCount' => 3]], $line['periods']);

        $summary = $this->api($this->client, 'GET', '/api/plannings', token: $this->s['creator'])[0];
        self::assertSame(1, $summary['periodCount']);
        self::assertSame($period['stableId'], $summary['nextPeriod']['stableId']);
    }

    public function testPlanningSummariesCarryTheStatusOfEachPeriodAndTheDetailItsPeriodRights(): void
    {
        $this->publishPeriodOne();
        $period2 = $this->createPeriod('2027-08-01');
        self::assertResponseStatusCodeSame(201);

        // Lot L6: a card shows the status of each period, never one global "published".
        $summary = $this->api($this->client, 'GET', '/api/plannings', token: $this->s['creator'])[0];
        self::assertSame(2, $summary['periodCount']);
        self::assertNull($summary['currentPeriod'], 'today (2026) is before period 1');
        $next = $summary['nextPeriod'];
        self::assertSame([1, 'published', 'future', '2027-04-30'], [$next['number'], $next['status'], $next['timeState'], $next['lastDay']]);
        self::assertSame(1, $next['lineCounts']['published']);
        self::assertNotNull($next['publishedAt']);
        self::assertNotNull($next['generatedAt']);
        self::assertArrayNotHasKey('canDelete', $next, 'a summary, not the full period');
        self::assertArrayNotHasKey('lines', $next);
        $latest = $summary['latestPeriod'];
        self::assertSame([$period2['stableId'], 2, 'preparation', '2027-07-31'], [$latest['stableId'], $latest['number'], $latest['status'], $latest['lastDay']]);
        self::assertSame(0, $latest['collections']['open']);
        self::assertSame(92, $latest['collections']['uncoveredDays']);
        self::assertNull($latest['publishedAt']);

        $detail = $this->api($this->client, 'GET', "/api/plannings/{$this->s['planningId']}", token: $this->s['creator']);
        self::assertSame($latest, $detail['latestPeriod']);
        self::assertTrue($detail['canCreatePeriod']);
        self::assertTrue($detail['canOpenPeriodCollection']);

        // A plain member: neither right (the flag is on in this environment).
        $member = $this->api($this->client, 'GET', "/api/plannings/{$this->s['planningId']}", token: $this->s['alice']);
        self::assertResponseIsSuccessful();
        self::assertFalse($member['canCreatePeriod']);
        self::assertFalse($member['canOpenPeriodCollection']);
        self::assertSame('published', $member['nextPeriod']['status']);
    }

    public function testEachPeriodOfALineCountsThePeopleWhoTakePartInIt(): void
    {
        $period2 = $this->createPeriod('2027-08-01');
        self::assertResponseStatusCodeSame(201);
        // Bob leaves on the first day of period 2 (exclusive end): he takes no part in it.
        $this->api($this->client, 'POST', "/api/plannings/{$this->s['planningId']}/teams/{$this->s['teamId']}/members/{$this->memberIdOf($this->client, $this->s, 'bob@example.com')}/end", ['membershipEnd' => '2027-05-01'], $this->s['creator']);
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());
        // Alice leaves, then comes back within period 2: two stints, one person.
        $alice = $this->memberIdOf($this->client, $this->s, 'alice@example.com');
        $this->api($this->client, 'POST', "/api/plannings/{$this->s['planningId']}/teams/{$this->s['teamId']}/members/{$alice}/end", ['membershipEnd' => '2027-06-01'], $this->s['creator']);
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());
        $aliceId = (string) $this->db()->fetchOne("SELECT stable_id FROM users WHERE email = 'alice@example.com'");
        $this->api($this->client, 'POST', "/api/plannings/{$this->s['planningId']}/teams/{$this->s['teamId']}/members", ['userStableId' => $aliceId, 'role' => 'MEMBER', 'membershipStart' => '2027-07-01'], $this->s['creator']);
        self::assertResponseStatusCodeSame(201, (string) $this->client->getResponse()->getContent());

        $line = $this->api($this->client, 'GET', "/api/plannings/{$this->s['planningId']}", token: $this->s['creator'])['lines'][0];
        $counts = array_column($line['periods'], 'memberCount', 'number');
        self::assertSame([1 => 3, 2 => 2], $counts, 'period 1: admin, alice, bob; period 2: admin and alice, counted once');
        self::assertSame($period2['stableId'], $line['periods'][1]['periodStableId']);
    }

    // --- creation ---------------------------------------------------------------------------------

    public function testPeriodsTwoThreeAndFourAreCreatedContiguousWithAPeriodForEachActiveLine(): void
    {
        $this->addSecondaryLine($this->client, $this->s, ['junior@example.com']);
        $period1 = $this->lastPeriodId();

        $period2 = $this->createPeriod('2027-08-01');
        self::assertResponseStatusCodeSame(201);
        self::assertSame([2, '2027-05-01', '2027-08-01', '2027-07-31'], [$period2['number'], $period2['startsAt'], $period2['endsAt'], $period2['lastDay']]);
        self::assertSame('preparation', $period2['status'], 'no collection is opened on creation (A)');
        self::assertSame(['open' => 0, 'closed' => 0, 'participants' => 0, 'deadline' => null, 'uncoveredDays' => 92], $period2['collections']);
        self::assertNull($period2['preparation']);
        self::assertCount(2, $period2['lines']);
        self::assertSame(['DRAFT', 'DRAFT'], array_column($period2['lines'], 'status'));
        self::assertEmailCount(0);

        $period3 = $this->createPeriod('2027-09-01');
        self::assertResponseStatusCodeSame(201);
        $period4 = $this->createPeriod('2027-12-01');
        self::assertResponseStatusCodeSame(201);
        self::assertSame([3, '2027-08-01', 4, '2027-09-01'], [$period3['number'], $period3['startsAt'], $period4['number'], $period4['startsAt']]);

        $list = $this->periods();
        self::assertSame([1, 2, 3, 4], array_column($list['periods'], 'number'));
        self::assertSame([false, false, false, true], array_column($list['periods'], 'isLast'));
        self::assertSame($period1, $list['periods'][0]['stableId']);

        $detail = $this->api($this->client, 'GET', "/api/plannings/{$this->s['planningId']}", token: $this->s['creator']);
        self::assertSame(['2027-01-01', '2027-12-01'], [$detail['startsAt'], $detail['endsAt']], 'the planning is the envelope of its periods (I5)');
        foreach ($detail['lines'] as $line) {
            self::assertSame([1, 2, 3, 4], array_column($line['periods'], 'number'));
        }

        $db = $this->db();
        // Every period of a line has its cycle's dates; a team's FairnessPeriod covers them all; no duty was copied.
        self::assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM planning_periods pp JOIN planning_cycles c ON c.id = pp.planning_cycle_id WHERE pp.starts_at <> c.starts_at OR pp.ends_at <> c.ends_at'));
        self::assertSame(2, (int) $db->fetchOne("SELECT COUNT(*) FROM fairness_periods f JOIN planning_teams t ON t.id = f.team_id JOIN plannings p ON p.id = t.planning_id WHERE p.stable_id = ? AND f.starts_at = '2027-01-01' AND f.ends_at = '2027-12-01'", [$this->s['planningId']]));
        self::assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM duties d JOIN planning_periods pp ON pp.id = d.planning_period_id JOIN planning_cycles c ON c.id = pp.planning_cycle_id WHERE c.number > 1'));
        self::assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM availability_collections a JOIN planning_cycles c ON c.id = a.planning_cycle_id WHERE c.number > 1'));
    }

    public function testAnInactiveLineGetsNoPeriod(): void
    {
        $this->addSecondaryLine($this->client, $this->s, []);
        $this->db()->executeStatement("UPDATE planning_lines SET active = false WHERE name = 'Juniors'");

        $period2 = $this->createPeriod('2027-08-01');

        self::assertResponseStatusCodeSame(201);
        self::assertCount(1, $period2['lines']);
    }

    public function testCreatingAfterAPublishedPeriodLeavesThatPeriodStrictlyUnchanged(): void
    {
        $this->publishPeriodOne();
        $period1 = $this->lastPeriodId();
        $before = $this->fingerprint($period1);

        $period2 = $this->createPeriod('2027-08-01');
        self::assertResponseStatusCodeSame(201);
        self::assertSame('preparation', $period2['status']);

        self::assertSame($before, $this->fingerprint($period1));
        $list = $this->periods();
        self::assertSame('published', $list['periods'][0]['status']);
        self::assertNotNull($list['periods'][0]['publishedAt']);
        self::assertFalse($list['periods'][0]['canEditEnd'], 'a published period never moves');
        self::assertFalse($list['periods'][0]['canEditStart'], 'nor its start');
    }

    public function testADoubleSubmissionCreatesOnePeriod(): void
    {
        $period1 = $this->lastPeriodId();

        $this->createPeriod('2027-08-01', $period1);
        self::assertResponseStatusCodeSame(201);
        $this->createPeriod('2027-08-01', $period1);
        $this->assertError(409, 'period_sequence_changed');

        self::assertCount(2, $this->periods()['periods']);
    }

    /**
     * Multi-line atomicity: the last line's period cannot be written (an
     * overlapping period of its team is there) — nothing of the new period
     * remains, neither the cycle nor the first line's period nor any
     * extended FairnessPeriod or envelope.
     */
    public function testAFailureOnTheLastLineCreatesNothing(): void
    {
        $this->addSecondaryLine($this->client, $this->s, []);
        $db = $this->db();
        $junior = $db->fetchAssociative("SELECT pp.team_id, pp.fairness_period_id FROM planning_periods pp JOIN planning_lines pl ON pl.id = pp.planning_line_id WHERE pl.name = 'Juniors'");
        self::assertNotFalse($junior);
        $db->executeStatement("UPDATE fairness_periods SET ends_at = '2027-09-01' WHERE id = ?", [$junior['fairness_period_id']]);
        $db->executeStatement(
            "INSERT INTO planning_periods (stable_id, team_id, fairness_period_id, name, starts_at, ends_at, status, created_at, updated_at) VALUES (gen_random_uuid(), ?, ?, 'Obstacle', '2027-06-01', '2027-07-01', 'DRAFT', NOW(), NOW())",
            [$junior['team_id'], $junior['fairness_period_id']],
        );
        $fairnessBefore = $db->fetchAllAssociative('SELECT id, starts_at, ends_at FROM fairness_periods ORDER BY id');

        $this->createPeriod('2027-08-01');
        $this->assertError(409, 'period_sequence_changed');

        self::assertCount(1, $this->periods()['periods']);
        self::assertSame(0, (int) $db->fetchOne("SELECT COUNT(*) FROM planning_periods WHERE starts_at = '2027-05-01'"));
        self::assertSame($fairnessBefore, $db->fetchAllAssociative('SELECT id, starts_at, ends_at FROM fairness_periods ORDER BY id'));
        self::assertSame('2027-05-01', $this->api($this->client, 'GET', "/api/plannings/{$this->s['planningId']}", token: $this->s['creator'])['endsAt']);
    }

    public function testInvalidRangesAreRefused(): void
    {
        $this->createPeriod('2027-05-07');
        $this->assertError(422, 'period_too_short');
        $this->createPeriod('2027-04-01');
        $this->assertError(422, 'period_too_short');
        $this->createPeriod('2027-02-30');
        $this->assertError(422, 'invalid_date');
        $this->api($this->client, 'POST', "/api/plannings/{$this->s['planningId']}/periods", ['endsAt' => '2027-08-01'], $this->s['creator']);
        $this->assertError(422, 'invalid_request');

        $this->createPeriod('2027-05-08');
        self::assertResponseStatusCodeSame(201, 'exactly 7 days is enough');
    }

    public function testNoPeriodIsCreatedWhileACalculationIsRunning(): void
    {
        $this->prepareLine($this->s['planningId'], [['2027-01-05', '2027-01-06']]);
        $this->api($this->client, 'POST', "/api/plannings/{$this->s['planningId']}/generations", [], $this->s['creator']);
        self::assertResponseStatusCodeSame(202);

        $this->createPeriod('2027-08-01');
        $this->assertError(409, 'job_in_progress');
    }

    // --- rights and errors ------------------------------------------------------------------------

    public function testOnlyTheCreatorManagesPeriodsAndAMemberReadsThem(): void
    {
        $period1 = $this->lastPeriodId();
        foreach (['admin', 'alice'] as $who) {
            $this->createPeriod('2027-08-01', $period1, $this->s[$who]);
            self::assertResponseStatusCodeSame(403, $who);
            $this->api($this->client, 'PATCH', "/api/plannings/{$this->s['planningId']}/periods/{$period1}", ['endsAt' => '2027-06-01', 'expectedEndsAt' => '2027-05-01'], $this->s[$who]);
            self::assertResponseStatusCodeSame(403, $who);
            $this->api($this->client, 'DELETE', "/api/plannings/{$this->s['planningId']}/periods/{$period1}?expected={$period1}", token: $this->s[$who]);
            self::assertResponseStatusCodeSame(403, $who);
        }

        $this->periods($this->s['alice']);
        self::assertResponseIsSuccessful();
        self::assertFalse($this->periods($this->s['alice'])['canCreate']);
        $this->periods($this->s['outsider']);
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', "/api/plannings/{$this->s['planningId']}/periods");
        self::assertResponseStatusCodeSame(401);
    }

    public function testAPeriodOfAnotherPlanningIsNotFound(): void
    {
        [$otherPlanning] = $this->createPlanningWithTeam($this->client, $this->s['creator'], 'Autre');
        $otherPeriod = $this->api($this->client, 'GET', "/api/plannings/{$otherPlanning}/periods", token: $this->s['creator'])['periods'][0]['stableId'];

        $this->api($this->client, 'PATCH', "/api/plannings/{$this->s['planningId']}/periods/{$otherPeriod}", ['endsAt' => '2027-06-01', 'expectedEndsAt' => '2027-05-01'], $this->s['creator']);
        $this->assertError(404, 'period_not_found');
        $this->api($this->client, 'GET', "/api/plannings/{$this->s['planningId']}/collection-status?period={$otherPeriod}", token: $this->s['creator']);
        $this->assertError(404, 'period_not_found');
        $this->api($this->client, 'GET', "/api/plannings/{$this->s['planningId']}/collection-status?period=not-a-uuid", token: $this->s['creator']);
        $this->assertError(404, 'period_not_found');
    }

    // --- end of the last period (B) ---------------------------------------------------------------

    /**
     * A collection someone already touched (a calendar change inside it) keeps its dates (docs/decisions.md D207: only an untouched collection of
     * a blank period follows it — PlanningPeriodDatesTest).
     */
    public function testTheEndOfTheLastPeriodGrowsKeepingItsCollectionsAndShrinksWhileNothingIsBeyond(): void
    {
        $period1 = $this->lastPeriodId();
        $this->api($this->client, 'POST', '/api/me/calendar', ['type' => 'UNAVAILABLE', 'startsAt' => '2027-02-10T00:00:00+01:00', 'endsAt' => '2027-02-12T00:00:00+01:00'], $this->s['alice']);
        self::assertResponseIsSuccessful();

        $grown = $this->api($this->client, 'PATCH', "/api/plannings/{$this->s['planningId']}/periods/{$period1}", ['endsAt' => '2027-06-01', 'expectedEndsAt' => '2027-05-01'], $this->s['creator']);
        self::assertResponseIsSuccessful();
        self::assertSame('2027-06-01', $grown['endsAt']);
        self::assertFalse($grown['generationOutdated']);
        self::assertSame(31, $grown['collections']['uncoveredDays'], 'the added days are not covered: collections keep their dates');
        self::assertSame('2027-05-01', $this->db()->fetchOne('SELECT ends_at FROM availability_collections'));

        $shrunk = $this->api($this->client, 'PATCH', "/api/plannings/{$this->s['planningId']}/periods/{$period1}", ['endsAt' => '2027-05-15', 'expectedEndsAt' => '2027-06-01'], $this->s['creator']);
        self::assertResponseIsSuccessful();
        self::assertSame('2027-05-15', $shrunk['endsAt']);

        $this->api($this->client, 'PATCH', "/api/plannings/{$this->s['planningId']}/periods/{$period1}", ['endsAt' => '2027-04-15', 'expectedEndsAt' => '2027-05-15'], $this->s['creator']);
        $this->assertError(409, 'collection_answered'); // D208: the refusal says why it keeps its window

        $this->api($this->client, 'PATCH', "/api/plannings/{$this->s['planningId']}/periods/{$period1}", ['endsAt' => '2027-06-01', 'expectedEndsAt' => '2027-06-01'], $this->s['creator']);
        $this->assertError(409, 'period_changed');

        $db = $this->db();
        self::assertSame(['2027-05-15'], array_values(array_unique($db->fetchFirstColumn('SELECT pp.ends_at FROM planning_periods pp JOIN planning_lines pl ON pl.id = pp.planning_line_id JOIN plannings p ON p.id = pl.planning_id WHERE p.stable_id = ?', [$this->s['planningId']]))));
        self::assertSame('2027-05-15', $db->fetchOne('SELECT f.ends_at FROM fairness_periods f JOIN planning_periods pp ON pp.fairness_period_id = f.id LIMIT 1'));
        self::assertSame('2027-05-15', $this->api($this->client, 'GET', "/api/plannings/{$this->s['planningId']}", token: $this->s['creator'])['endsAt']);
    }

    public function testGrowingAGeneratedPeriodFlagsItsGenerationAsOutdatedAndShrinkingIsRefused(): void
    {
        $this->prepareLine($this->s['planningId'], [['2027-01-05', '2027-01-06']]);
        $this->generate($this->client, $this->s);
        $period1 = $this->lastPeriodId();

        $this->api($this->client, 'PATCH', "/api/plannings/{$this->s['planningId']}/periods/{$period1}", ['endsAt' => '2027-04-20', 'expectedEndsAt' => '2027-05-01'], $this->s['creator']);
        $this->assertError(409, 'period_has_generation');

        $grown = $this->api($this->client, 'PATCH', "/api/plannings/{$this->s['planningId']}/periods/{$period1}", ['endsAt' => '2027-06-01', 'expectedEndsAt' => '2027-05-01'], $this->s['creator']);
        self::assertResponseIsSuccessful();
        self::assertTrue($grown['generationOutdated']);
        self::assertSame('generated', $grown['status']);
    }

    public function testShrinkingIsRefusedOverMaterializedDuties(): void
    {
        $this->prepareLine($this->s['planningId'], [['2027-04-20', '2027-04-21']]);
        $period1 = $this->lastPeriodId();
        $this->db()->executeStatement("UPDATE availability_collections SET ends_at = '2027-04-10'");

        $this->api($this->client, 'PATCH', "/api/plannings/{$this->s['planningId']}/periods/{$period1}", ['endsAt' => '2027-04-15', 'expectedEndsAt' => '2027-05-01'], $this->s['creator']);
        $this->assertError(409, 'period_has_duties_after');
    }

    public function testOnlyTheLastUnlockedPeriodMoves(): void
    {
        $this->publishPeriodOne();
        $period1 = $this->lastPeriodId();

        $this->api($this->client, 'PATCH', "/api/plannings/{$this->s['planningId']}/periods/{$period1}", ['endsAt' => '2027-06-01', 'expectedEndsAt' => '2027-05-01'], $this->s['creator']);
        $this->assertError(409, 'period_locked');

        $this->createPeriod('2027-08-01');
        // D207: an earlier period's end is the boundary with the next one — never once it is published.
        $this->api($this->client, 'PATCH', "/api/plannings/{$this->s['planningId']}/periods/{$period1}", ['endsAt' => '2027-06-01', 'expectedEndsAt' => '2027-05-01'], $this->s['creator']);
        $this->assertError(409, 'period_locked');

        $period2 = $this->lastPeriodId();
        $this->api($this->client, 'PATCH', "/api/plannings/{$this->s['planningId']}/periods/{$period2}", ['endsAt' => '2027-05-05', 'expectedEndsAt' => '2027-08-01'], $this->s['creator']);
        $this->assertError(422, 'period_too_short');
        $this->api($this->client, 'PATCH', "/api/plannings/{$this->s['planningId']}/periods/{$period2}", ['endsAt' => '2027-07-01', 'expectedEndsAt' => '2027-08-01'], $this->s['creator']);
        self::assertResponseIsSuccessful();
    }

    /**
     * A period whose line was deleted (orphan, docs/decisions.md D188 — one
     * exists in production, published, in its planning's cycle 1) keeps its
     * history and its dates: it never locks the period, never moves with
     * it, and never closes it to a new line.
     */
    public function testAnOrphanPeriodNeitherLocksNorMovesWithItsPeriod(): void
    {
        $this->addSecondaryLine($this->client, $this->s, ['junior@example.com']);
        $this->db()->executeStatement("UPDATE planning_periods SET planning_line_id = NULL, status = 'PUBLISHED' WHERE name = 'Juniors'");
        $this->db()->executeStatement("DELETE FROM planning_lines WHERE name = 'Juniors'");
        $period1 = $this->lastPeriodId();

        $this->api($this->client, 'PATCH', "/api/plannings/{$this->s['planningId']}/periods/{$period1}", ['endsAt' => '2027-06-01', 'expectedEndsAt' => '2027-05-01'], $this->s['creator']);
        self::assertResponseIsSuccessful();
        self::assertSame(
            ['status' => 'PUBLISHED', 'starts_at' => '2027-01-01', 'ends_at' => '2027-05-01'],
            $this->db()->fetchAssociative("SELECT status, starts_at, ends_at FROM planning_periods WHERE name = 'Juniors'"),
        );
        $audit = static::getContainer()->get(PlanningCycleAudit::class);
        self::assertSame([], $audit->violations($audit->report()));

        $line = $this->api($this->client, 'POST', "/api/plannings/{$this->s['planningId']}/lines", ['name' => 'Renfort'], $this->s['creator']);
        self::assertResponseStatusCodeSame(201);
        self::assertSame([1], array_column($line['periods'], 'number'));
    }

    // --- deletion of the last period (L) ---------------------------------------------------------

    public function testTheLastPeriodWithoutAnyHistoryIsDeletedAndEverythingGoesBack(): void
    {
        $this->addSecondaryLine($this->client, $this->s, []);
        $before = $this->periods();
        $period1 = $before['periods'][0]['stableId'];
        $fairnessBefore = $this->db()->fetchAllAssociative('SELECT id, starts_at, ends_at FROM fairness_periods ORDER BY id');
        $period2 = $this->createPeriod('2027-08-01')['stableId'];
        // A duty materialized in it (a preflight would do it): deleted with it.
        $line = static::getContainer()->get(\App\Repository\PlanningLineRepository::class)->findByPlanning(static::getContainer()->get(\App\Repository\PlanningRepository::class)->findOneByStableId($this->s['planningId']))[0];
        $cycle2 = static::getContainer()->get(\App\Repository\PlanningCycleRepository::class)->findOneForPlanning($line->getPlanning(), $period2);
        $this->createDuty(static::getContainer()->get(\App\Service\DutyMaterializationService::class), $line->periodIn($cycle2), $this->createDutyType(static::getContainer()->get(\Doctrine\ORM\EntityManagerInterface::class), $line->getPlanningTeam()), '2027-06-01', '2027-06-02');

        $this->api($this->client, 'DELETE', "/api/plannings/{$this->s['planningId']}/periods/{$period1}?expected={$period2}", token: $this->s['creator']);
        $this->assertError(409, 'period_not_deletable');
        $this->api($this->client, 'DELETE', "/api/plannings/{$this->s['planningId']}/periods/{$period2}?expected={$period1}", token: $this->s['creator']);
        $this->assertError(409, 'period_sequence_changed');
        $this->api($this->client, 'DELETE', "/api/plannings/{$this->s['planningId']}/periods/{$period2}", token: $this->s['creator']);
        $this->assertError(422, 'invalid_request');

        $this->api($this->client, 'DELETE', "/api/plannings/{$this->s['planningId']}/periods/{$period2}?expected={$period2}", token: $this->s['creator']);
        self::assertResponseStatusCodeSame(204);

        self::assertSame($before['periods'], $this->periods()['periods']);
        self::assertSame($fairnessBefore, $this->db()->fetchAllAssociative('SELECT id, starts_at, ends_at FROM fairness_periods ORDER BY id'));
        self::assertSame(0, (int) $this->db()->fetchOne("SELECT COUNT(*) FROM duties WHERE local_date >= '2027-05-01'"));
        self::assertSame('2027-05-01', $this->api($this->client, 'GET', "/api/plannings/{$this->s['planningId']}", token: $this->s['creator'])['endsAt']);
    }

    public function testAPeriodWithACollectionAGenerationAPublicationOrAJobIsNeverDeleted(): void
    {
        $period2 = $this->createPeriod('2027-08-01')['stableId'];
        $cycle2 = $this->cycleId($period2);
        $planningId = (int) $this->db()->fetchOne('SELECT planning_id FROM planning_cycles WHERE id = ?', [$cycle2]);
        $userId = (int) $this->db()->fetchOne("SELECT id FROM users WHERE email = 'creator@example.com'");
        $delete = fn () => $this->api($this->client, 'DELETE', "/api/plannings/{$this->s['planningId']}/periods/{$period2}?expected={$period2}", token: $this->s['creator']);

        $cases = [
            'period_has_collection' => ["INSERT INTO availability_collections (stable_id, starts_at, ends_at, opened_at, status, created_at, updated_at, planning_id, created_by_id, planning_cycle_id) VALUES (gen_random_uuid(), '2027-05-01', '2027-06-01', NOW(), 'OPEN', NOW(), NOW(), ?, ?, ?)", [$planningId, $userId, $cycle2]],
            'period_has_generation' => ["INSERT INTO planning_generations (stable_id, status, created_at, updated_at, planning_period_id, legal_min_rest_enabled, team_min_rest_enabled, lock_version, mode) SELECT gen_random_uuid(), 'DRAFT', NOW(), NOW(), id, false, false, 1, 'GENERATE' FROM planning_periods WHERE planning_cycle_id = ? LIMIT 1", [$cycle2]],
            'period_has_publication' => ["INSERT INTO planning_publications (stable_id, planning_id, kind, published_by_id, changed_duty_count, published_at, planning_cycle_id, cycle_complete) VALUES (gen_random_uuid(), ?, 'FIRST', ?, 0, NOW(), ?, TRUE)", [$planningId, $userId, $cycle2]],
            'period_has_job' => ["INSERT INTO planning_jobs (stable_id, kind, status, created_at, planning_id, requested_by_id, planning_cycle_id) VALUES (gen_random_uuid(), 'GENERATE', 'SUCCEEDED', NOW(), ?, ?, ?)", [$planningId, $userId, $cycle2]],
        ];
        foreach ($cases as $code => [$sql, $params]) {
            $this->db()->executeStatement('SAVEPOINT history');
            $this->db()->executeStatement($sql, $params);
            $delete();
            $this->assertError(409, $code);
            $this->db()->executeStatement('ROLLBACK TO SAVEPOINT history');
        }

        $delete();
        self::assertResponseStatusCodeSame(204);
    }

    // --- collections of a period (A, §5.9) --------------------------------------------------------

    public function testAFollowingPeriodHasItsOwnCollectionsAndParticipants(): void
    {
        $period1 = $this->lastPeriodId();
        $before = $this->fingerprint($period1);
        $period2 = $this->createPeriod('2027-08-01')['stableId'];

        $opened = $this->api($this->client, 'POST', "/api/plannings/{$this->s['planningId']}/periods/{$period2}/availability-collections", ['deadline' => '2027-04-15'], $this->s['creator']);
        self::assertResponseStatusCodeSame(201);
        self::assertSame(['2027-05-01', '2027-08-01'], [$opened['startsAt'], $opened['endsAt']], 'by default, the part of the period not covered yet');
        self::assertSame(2, $opened['period']['number']);
        self::assertEmailCount(0);

        $this->api($this->client, 'POST', "/api/plannings/{$this->s['planningId']}/periods/{$period2}/availability-collections", [], $this->s['creator']);
        $this->assertError(409, 'period_fully_collected');
        $this->api($this->client, 'POST', "/api/plannings/{$this->s['planningId']}/periods/{$period2}/availability-collections", ['startsAt' => '2027-04-20', 'endsAt' => '2027-05-20'], $this->s['creator']);
        $this->assertError(422, 'window_outside_period');
        // The planning-level endpoint keeps the same rule: one window, one period.
        $this->api($this->client, 'POST', "/api/plannings/{$this->s['planningId']}/availability-collections", ['startsAt' => '2027-04-20', 'endsAt' => '2027-05-20'], $this->s['creator']);
        $this->assertError(422, 'window_outside_period');

        // Alice changes her calendar in period 2 only: period 1 is unchanged.
        $this->api($this->client, 'POST', '/api/me/calendar', ['type' => 'UNAVAILABLE', 'startsAt' => '2027-06-10T00:00:00+01:00', 'endsAt' => '2027-06-12T00:00:00+01:00'], $this->s['alice']);
        self::assertResponseIsSuccessful();
        self::assertSame($before, $this->fingerprint($period1));

        $status1 = $this->api($this->client, 'GET', "/api/plannings/{$this->s['planningId']}/collection-status?period={$period1}", token: $this->s['creator']);
        $status2 = $this->api($this->client, 'GET', "/api/plannings/{$this->s['planningId']}/collection-status?period={$period2}", token: $this->s['creator']);
        self::assertSame([1, 2], [$status1['period']['number'], $status2['period']['number']]);
        self::assertSame([3, 3], [$status1['summary']['concernedCount'], $status2['summary']['concernedCount']]);
        $aliceId = $this->api($this->client, 'GET', '/api/me', token: $this->s['alice'])['stableId'];
        $alice = static fn (array $status): array => array_values(array_filter($status['members'], static fn (array $m): bool => $aliceId === $m['userStableId']))[0];
        self::assertNull($alice($status1)['lastAvailabilityChangeAt'], 'a change in period 2 says nothing of period 1');
        self::assertNotNull($alice($status2)['lastAvailabilityChangeAt']);
        self::assertSame([0, 1], [$alice($status1)['unavailabilityCount'], $alice($status2)['unavailabilityCount']]);
        self::assertSame('2027-04-15', $status2['availabilityDeadline']);
        self::assertNull($status1['availabilityDeadline']);

        $list = $this->periods();
        self::assertSame(['collecting', 'collecting'], array_column($list['periods'], 'status'));
        self::assertSame(3, $list['periods'][1]['collections']['participants']);
        self::assertSame(0, $list['periods'][1]['collections']['uncoveredDays']);

        // Reminders and the deadline of one period only.
        $reminded = $this->api($this->client, 'POST', "/api/plannings/{$this->s['planningId']}/reminders/participants?period={$period2}", [], $this->s['creator']);
        self::assertResponseIsSuccessful();
        self::assertSame(2, $reminded['period']['number']);
        self::assertSame(3, $reminded['targetedCount'], 'every participant of period 2, Alice included (D214)');
        $this->api($this->client, 'PATCH', "/api/plannings/{$this->s['planningId']}/settings?period={$period1}", ['availabilityDeadline' => '2027-03-01'], $this->s['creator']);
        self::assertResponseIsSuccessful();
        self::assertSame(['2027-03-01', '2027-04-15'], $this->db()->fetchFirstColumn('SELECT deadline FROM availability_collections ORDER BY starts_at'));
    }

    public function testAClosedCollectionNeverReopens(): void
    {
        $period2 = $this->createPeriod('2027-08-01')['stableId'];
        $opened = $this->api($this->client, 'POST', "/api/plannings/{$this->s['planningId']}/periods/{$period2}/availability-collections", [], $this->s['creator']);
        $this->api($this->client, 'POST', "/api/availability-collections/{$opened['stableId']}/close", [], $this->s['creator']);
        self::assertResponseIsSuccessful();

        $this->api($this->client, 'POST', "/api/plannings/{$this->s['planningId']}/periods/{$period2}/availability-collections", [], $this->s['creator']);
        $this->assertError(409, 'period_fully_collected');
    }

    // --- the flows not period-aware yet refuse (D187) --------------------------------------------

    public function testThePastFlowsRefuseAPlanningWithSeveralPeriodsInsteadOfGuessingOne(): void
    {
        $this->publishPeriodOne();
        $this->createPeriod('2027-08-01');
        self::assertResponseStatusCodeSame(201);
        $p = $this->s['planningId'];

        // Lot L3 (D189): generation and completion work by period — never a guessed one. Lot L4c (D196): publication too.
        foreach ([
            ['GET', "/api/plannings/{$p}/generation-preflight"],
            ['POST', "/api/plannings/{$p}/generations"],
            ['POST', "/api/plannings/{$p}/complete"],
            ['POST', "/api/plannings/{$p}/publish"],
            ['POST', "/api/plannings/{$p}/republish"],
        ] as [$method, $uri]) {
            $this->api($this->client, $method, $uri, 'POST' === $method ? [] : null, $this->s['creator']);
            $this->assertError(422, 'period_required');
        }

        // A read of the publication answers for the default period (D194/D196), and says which one.
        $preflight = $this->api($this->client, 'GET', "/api/plannings/{$p}/publication-preflight", token: $this->s['creator']);
        self::assertResponseIsSuccessful();
        self::assertSame(1, $preflight['period']['number'], 'the next period: today is before the planning');

        // Lot L5 (D201): the statistics read one named period — never a guess on several.
        $this->api($this->client, 'GET', "/api/plannings/{$p}/statistics", token: $this->s['creator']);
        $this->assertError(422, 'period_required');
        $cycle2 = $this->db()->fetchOne('SELECT c.stable_id FROM planning_cycles c JOIN plannings pl ON pl.id = c.planning_id WHERE pl.stable_id = ? AND c.number = 2', [$p]);
        $statistics = $this->api($this->client, 'GET', "/api/plannings/{$p}/statistics?period={$cycle2}", token: $this->s['creator']);
        self::assertResponseIsSuccessful();
        self::assertSame(2, $statistics['period']['number']);

        // The flows later lots adapt still refuse it.
        foreach ([
            ['POST', "/api/plannings/{$p}/extensions"],
        ] as [$method, $uri]) {
            $this->api($this->client, $method, $uri, 'POST' === $method ? ['endsAt' => '2027-09-01'] : null, $this->s['creator']);
            $this->assertError(409, 'multiple_periods_not_supported');
        }

        $period = $this->db()->fetchOne('SELECT pp.stable_id FROM planning_periods pp JOIN planning_cycles c ON c.id = pp.planning_cycle_id WHERE c.number = 2 LIMIT 1');
        $this->api($this->client, 'POST', "/api/planning-periods/{$period}/generations", [], $this->s['admin']);
        $this->assertError(409, 'multiple_periods_not_supported');

        // Lot L4a (D194): the calendar reads one period — ?period=, else the default (current, else next, else
        // last); never two periods merged.
        $period1 = $this->db()->fetchOne('SELECT stable_id FROM planning_cycles WHERE number = 1 AND planning_id = (SELECT id FROM plannings WHERE stable_id = ?)', [$p]);
        $period2 = $this->db()->fetchOne('SELECT stable_id FROM planning_cycles WHERE number = 2 AND planning_id = (SELECT id FROM plannings WHERE stable_id = ?)', [$p]);
        $default = $this->api($this->client, 'GET', "/api/plannings/{$p}/result", token: $this->s['creator']);
        self::assertResponseIsSuccessful();
        self::assertSame(['stableId' => $period1, 'number' => 1, 'startsAt' => '2027-01-01', 'endsAt' => '2027-05-01', 'lastDay' => '2027-04-30'], $default['period']);
        self::assertSame(['2027-01-05', '2027-01-12'], array_column($default['lines'][0]['duties'], 'date'));
        $second = $this->api($this->client, 'GET', "/api/plannings/{$p}/result?period={$period2}", token: $this->s['creator']);
        self::assertResponseIsSuccessful();
        self::assertSame(2, $second['period']['number']);
        self::assertSame([], $second['lines'][0]['duties'], 'period 1 duties never shown in period 2');
        self::assertNull($second['lines'][0]['generationStableId']);
        self::assertSame('NO_DEMAND', $second['lines'][0]['applicability']['state']);
        $assignments = $this->api($this->client, 'GET', "/api/plannings/{$p}/assignments", token: $this->s['creator']);
        self::assertResponseIsSuccessful();
        self::assertSame(1, $assignments['period']['number']);
        self::assertCount(2, $assignments['assignments']);
        $assignments2 = $this->api($this->client, 'GET', "/api/plannings/{$p}/assignments?period={$period2}", token: $this->s['creator']);
        self::assertResponseIsSuccessful();
        self::assertSame(2, $assignments2['period']['number']);
        self::assertSame([], $assignments2['assignments']);
        self::assertSame([], $assignments2['generations']);

        // What still reads: the planning, its periods, its collections. Lot L4e (D198): "Mes gardes" reads every
        // published period — here period 1 only, period 2 being a draft.
        $this->api($this->client, 'GET', "/api/plannings/{$p}", token: $this->s['creator']);
        self::assertResponseIsSuccessful();
        $this->api($this->client, 'GET', "/api/plannings/{$p}/collection-status", token: $this->s['creator']);
        self::assertResponseIsSuccessful();
        $mine = [];
        foreach (['admin', 'alice', 'bob'] as $who) {
            array_push($mine, ...$this->api($this->client, 'GET', '/api/me/duties', token: $this->s[$who])['duties']);
            self::assertResponseIsSuccessful();
        }
        self::assertSame([1, 1], array_map(static fn (array $d): int => $d['period']['number'], $mine), 'the two published duties of period 1, once each');
    }

    // --- lines (F, §5.5) --------------------------------------------------------------------------

    public function testALineStartsAtAPeriodNeverInOneThatIsPublishedOrOver(): void
    {
        $this->publishPeriodOne();
        $period1 = $this->lastPeriodId();
        $period2 = $this->createPeriod('2027-08-01')['stableId'];
        $period3 = $this->createPeriod('2027-11-01')['stableId'];

        // Default: the earliest period from which all are open — period 2.
        $line = $this->api($this->client, 'POST', "/api/plannings/{$this->s['planningId']}/lines", ['name' => 'Renfort'], $this->s['creator']);
        self::assertResponseStatusCodeSame(201);
        self::assertSame([2, 3], array_column($line['periods'], 'number'));
        self::assertNull($line['planningPeriodStableId'], 'the default period is period 1 (the next one), where it takes no part');

        // From period 3 only.
        $late = $this->api($this->client, 'POST', "/api/plannings/{$this->s['planningId']}/lines", ['name' => 'Nuit', 'fromPeriodStableId' => $period3], $this->s['creator']);
        self::assertResponseStatusCodeSame(201);
        self::assertSame([3], array_column($late['periods'], 'number'));

        $this->api($this->client, 'POST', "/api/plannings/{$this->s['planningId']}/lines", ['name' => 'Jour', 'fromPeriodStableId' => $period1], $this->s['creator']);
        $this->assertError(409, 'period_not_eligible');
        $this->api($this->client, 'POST', "/api/plannings/{$this->s['planningId']}/lines", ['name' => 'Jour', 'fromPeriodStableId' => 'not-a-period'], $this->s['creator']);
        $this->assertError(404, 'period_not_found');

        // A later period already published: start later.
        $cycle3 = $this->cycleId($period3);
        $this->db()->executeStatement("UPDATE planning_periods SET status = 'PUBLISHED' WHERE planning_cycle_id = ?", [$cycle3]);
        $this->api($this->client, 'POST', "/api/plannings/{$this->s['planningId']}/lines", ['name' => 'Jour', 'fromPeriodStableId' => $period2], $this->s['creator']);
        $this->assertError(409, 'later_period_published');
    }

    public function testALineWithNoOpenPeriodWaitsForTheNextOne(): void
    {
        $this->publishPeriodOne();

        $line = $this->api($this->client, 'POST', "/api/plannings/{$this->s['planningId']}/lines", ['name' => 'Renfort'], $this->s['creator']);
        self::assertResponseStatusCodeSame(201);
        self::assertSame([], $line['periods']);
        self::assertSame(0, (int) $this->db()->fetchOne("SELECT COUNT(*) FROM fairness_periods f JOIN planning_teams t ON t.id = f.team_id WHERE t.name = 'Renfort'"), 'no data made up');

        // The published planning keeps reading and republishing exactly as before.
        $this->api($this->client, 'GET', "/api/plannings/{$this->s['planningId']}/result", token: $this->s['creator']);
        self::assertResponseIsSuccessful();
        $this->api($this->client, 'GET', "/api/plannings/{$this->s['planningId']}/publication-preflight", token: $this->s['creator']);
        self::assertResponseIsSuccessful();

        $period2 = $this->createPeriod('2027-08-01');
        self::assertResponseStatusCodeSame(201);
        self::assertCount(2, $period2['lines']);
        // Its FairnessPeriod is created with its first period (§3.5).
        self::assertSame(['starts_at' => '2027-05-01', 'ends_at' => '2027-08-01'], $this->db()->fetchAssociative("SELECT f.starts_at, f.ends_at FROM fairness_periods f JOIN planning_teams t ON t.id = f.team_id WHERE t.name = 'Renfort'"));
    }

    public function testALineWithoutHistoryIsDeletedWithItsPeriodsAndALineWithHistoryIsNot(): void
    {
        $this->addSecondaryLine($this->client, $this->s, ['junior@example.com']);
        $this->createPeriod('2027-08-01');
        $detail = $this->api($this->client, 'GET', "/api/plannings/{$this->s['planningId']}", token: $this->s['creator']);
        $juniors = $detail['lines'][1]['stableId'];

        $this->api($this->client, 'DELETE', "/api/plannings/{$this->s['planningId']}/lines/{$juniors}", token: $this->s['creator']);
        self::assertResponseStatusCodeSame(204);
        self::assertSame(0, (int) $this->db()->fetchOne("SELECT COUNT(*) FROM planning_periods WHERE name = 'Juniors'"), 'no orphan period is left behind any more');

        // History: the primary line's generation would forbid it — shown here on a fresh secondary line generated once.
        $this->api($this->client, 'DELETE', "/api/plannings/{$this->s['planningId']}/periods/{$this->lastPeriodId()}?expected={$this->lastPeriodId()}", token: $this->s['creator']);
        self::assertResponseStatusCodeSame(204);
        $teamId = $this->addSecondaryLine($this->client, $this->s, ['junior2@example.com']);
        self::assertNotSame('', $teamId);
        $this->prepareLine($this->s['planningId'], [['2027-01-05', '2027-01-06']]);
        $this->prepareLine($this->s['planningId'], [['2027-01-07', '2027-01-08']], lineIndex: 1);
        $this->generate($this->client, $this->s);
        $renfort = $this->api($this->client, 'GET', "/api/plannings/{$this->s['planningId']}", token: $this->s['creator'])['lines'][1]['stableId'];
        $this->api($this->client, 'DELETE', "/api/plannings/{$this->s['planningId']}/lines/{$renfort}", token: $this->s['creator']);
        $this->assertError(409, 'line_has_history');
    }
}
