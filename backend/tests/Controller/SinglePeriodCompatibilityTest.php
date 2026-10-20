<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\PlanningLineType;
use App\Entity\UserAvailabilityType;
use App\Repository\PlanningRepository;
use App\Repository\UserRepository;
use App\Service\PlanningAbsenceExportService;
use App\Service\PlanningCycleAudit;
use App\Service\PlanningLineService;
use App\Service\UserAvailabilityService;
use App\Tests\DutySwapTestHelpers;
use App\Tests\PlanningExportTestHelpers;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Mime\Email;

/**
 * Single-period compatibility (docs/decisions.md D188): with
 * MEDVUE_PERIOD_CREATION_ENABLED=0 — the shipped default — and a planning
 * that has its only period, adding and deleting a line behave exactly as
 * before periods existed (master, before lots L1/L2):
 *
 * - PlanningLineService::addLine() always created the line's PlanningPeriod
 *   (DRAFT, the Planning's dates), whatever the status of the other lines
 *   and whether the planning was over;
 * - deleteLine() removed the line only: its period, generations,
 *   assignments and publication entries stayed in the database, referenced
 *   by no line.
 *
 * Fixture (pilotScenario): one planning 2027-01-01 → 2027-05-01, its primary
 * line "Seniors" with admin/alice/bob. The flag is turned off for these
 * tests only (the test environment enables it, .env.test).
 */
final class SinglePeriodCompatibilityTest extends WebTestCase
{
    use ClockSensitiveTrait;
    use DutySwapTestHelpers;
    use PlanningExportTestHelpers;

    private const FLAG = 'MEDVUE_PERIOD_CREATION_ENABLED';

    private KernelBrowser $client;

    /** @var array<string, mixed> */
    private array $s;

    /** @var array{0: mixed, 1: mixed} */
    private array $flag;

    protected function setUp(): void
    {
        // Read by the container when PlanningCycleService is built, in the kernel createClient() boots below.
        $this->flag = [$_ENV[self::FLAG] ?? null, $_SERVER[self::FLAG] ?? null];
        $_ENV[self::FLAG] = $_SERVER[self::FLAG] = '0';
        $this->client = static::createClient();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        [$_ENV[self::FLAG], $_SERVER[self::FLAG]] = $this->flag;
    }

    // --- helpers ---------------------------------------------------------------------------------

    private function db(): Connection
    {
        return static::getContainer()->get(Connection::class);
    }

    private function publishPeriodOne(): void
    {
        $this->prepareLine($this->s['planningId'], [['2027-01-05', '2027-01-06'], ['2027-01-12', '2027-01-13']]);
        $this->generate($this->client, $this->s);
        $this->api($this->client, 'POST', "/api/plannings/{$this->s['planningId']}/publish", [], $this->s['creator']);
        self::assertResponseIsSuccessful();
    }

    /** Everything the primary line holds: its period, duties, generations, assignments, publications and entries. */
    private function primaryFingerprint(): string
    {
        return (string) $this->db()->fetchOne(<<<'SQL'
            SELECT md5(string_agg(x, '|' ORDER BY x)) FROM (
              SELECT 'p' || ROW(pp.*)::text AS x FROM planning_periods pp JOIN planning_lines l ON l.id = pp.planning_line_id WHERE l.type = 'PRIMARY'
              UNION ALL SELECT 'd' || ROW(d.*)::text FROM duties d JOIN planning_periods pp ON pp.id = d.planning_period_id JOIN planning_lines l ON l.id = pp.planning_line_id WHERE l.type = 'PRIMARY'
              UNION ALL SELECT 'g' || ROW(g.*)::text FROM planning_generations g JOIN planning_periods pp ON pp.id = g.planning_period_id JOIN planning_lines l ON l.id = pp.planning_line_id WHERE l.type = 'PRIMARY'
              UNION ALL SELECT 'a' || ROW(a.*)::text FROM duty_assignments a JOIN duties d ON d.id = a.duty_id JOIN planning_periods pp ON pp.id = d.planning_period_id JOIN planning_lines l ON l.id = pp.planning_line_id WHERE l.type = 'PRIMARY'
              UNION ALL SELECT 'u' || ROW(u.*)::text FROM planning_publications u
              UNION ALL SELECT 'e' || ROW(e.*)::text FROM planning_publication_entries e
            ) s
        SQL);
    }

    /**
     * @return array<string, mixed>
     */
    private function periodNamed(string $name): array
    {
        $row = $this->db()->fetchAssociative('SELECT id, status, starts_at, ends_at, planning_cycle_id, planning_line_id FROM planning_periods WHERE name = ?', [$name]);
        self::assertIsArray($row, "the period of {$name}");

        return $row;
    }

    private function onlyCycleId(): int
    {
        return (int) $this->db()->fetchOne('SELECT c.id FROM planning_cycles c JOIN plannings p ON p.id = c.planning_id WHERE p.stable_id = ?', [$this->s['planningId']]);
    }

    /**
     * @param list<string> $expected the violations expected (D191: a deleted line's duties still held and not over)
     */
    private function assertAuditClean(array $expected = []): void
    {
        $audit = static::getContainer()->get(PlanningCycleAudit::class);
        self::assertSame($expected, $audit->violations($audit->report()));
    }

    // --- the flag ---------------------------------------------------------------------------------

    public function testThePeriodsStayClosedWhileTheFlagIsOff(): void
    {
        $this->s = $this->pilotScenario($this->client);
        $periods = $this->api($this->client, 'GET', "/api/plannings/{$this->s['planningId']}/periods", token: $this->s['creator']);
        $this->api($this->client, 'POST', "/api/plannings/{$this->s['planningId']}/periods", ['endsAt' => '2027-08-01', 'previousPeriodStableId' => $periods['periods'][0]['stableId']], $this->s['creator']);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('period_creation_disabled', json_decode((string) $this->client->getResponse()->getContent(), true)['error'] ?? null);

        // Lot L6: the screens never offer what the flag switches off.
        $detail = $this->api($this->client, 'GET', "/api/plannings/{$this->s['planningId']}", token: $this->s['creator']);
        self::assertTrue($detail['canManage']);
        self::assertFalse($detail['canCreatePeriod']);
        self::assertFalse($detail['canOpenPeriodCollection']);
        self::assertSame(1, $detail['periodCount']);
        self::assertSame($detail['periods'][0]['stableId'], $detail['latestPeriod']['stableId']);
    }

    // --- adding a line ----------------------------------------------------------------------------

    public function testALineAddedBeforePublicationGetsItsPeriodAsBefore(): void
    {
        $this->s = $this->pilotScenario($this->client);

        $line = $this->api($this->client, 'POST', "/api/plannings/{$this->s['planningId']}/lines", ['name' => 'Renfort'], $this->s['creator']);
        self::assertResponseStatusCodeSame(201);
        self::assertNotNull($line['planningPeriodStableId']);
        self::assertSame([1], array_column($line['periods'], 'number'));
        self::assertSame(
            ['status' => 'DRAFT', 'starts_at' => '2027-01-01', 'ends_at' => '2027-05-01', 'planning_cycle_id' => $this->onlyCycleId()],
            array_intersect_key($this->periodNamed('Renfort'), array_flip(['status', 'starts_at', 'ends_at', 'planning_cycle_id'])),
        );
        $this->assertAuditClean();
    }

    public function testALineAddedToAPublishedPlanningGetsItsPeriodAndIsNeverPublishedUnlessChosen(): void
    {
        $this->s = $this->pilotScenario($this->client);
        $this->publishPeriodOne();
        $primary = $this->primaryFingerprint();

        $line = $this->api($this->client, 'POST', "/api/plannings/{$this->s['planningId']}/lines", ['name' => 'Renfort'], $this->s['creator']);
        self::assertResponseStatusCodeSame(201);
        self::assertNotNull($line['planningPeriodStableId'], 'as before L1: the line has its period in the published planning');
        self::assertSame([1], array_column($line['periods'], 'number'));
        self::assertSame(['DRAFT', '2027-01-01', '2027-05-01'], array_values(array_intersect_key($this->periodNamed('Renfort'), array_flip(['status', 'starts_at', 'ends_at']))));
        self::assertSame(
            ['starts_at' => '2027-01-01', 'ends_at' => '2027-05-01'],
            $this->db()->fetchAssociative("SELECT f.starts_at, f.ends_at FROM fairness_periods f JOIN planning_teams t ON t.id = f.team_id WHERE t.name = 'Renfort'"),
        );
        self::assertSame($primary, $this->primaryFingerprint(), 'the published line is untouched');
        self::assertSame(0, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM planning_lines pl WHERE NOT EXISTS (SELECT 1 FROM planning_periods pp WHERE pp.planning_line_id = pl.id)'), 'every line has its period: the L2 down migration stays possible');
        $this->assertAuditClean();

        // What followed before L1, unchanged: the detail shows both lines with their period…
        $detail = $this->api($this->client, 'GET', "/api/plannings/{$this->s['planningId']}", token: $this->s['creator']);
        self::assertResponseIsSuccessful();
        self::assertCount(2, $detail['lines']);
        self::assertNotNull($detail['lines'][1]['planningPeriodStableId']);
        // …the planning-level generation stays blocked by the published primary line…
        $generation = $this->api($this->client, 'GET', "/api/plannings/{$this->s['planningId']}/generation-preflight", token: $this->s['creator']);
        self::assertResponseIsSuccessful();
        self::assertFalse($generation['canGenerate']);
        self::assertContains('PERIOD_LOCKED', array_column($generation['blockers'], 'code'));
        self::assertCount(2, $generation['lines']);
        // …the new line, with nothing demanded yet, is neutral: it no longer blocks the republication (D194)…
        $publication = $this->api($this->client, 'GET', "/api/plannings/{$this->s['planningId']}/publication-preflight", token: $this->s['creator']);
        self::assertResponseIsSuccessful();
        self::assertTrue($publication['republishable']);
        self::assertCount(1, $publication['lines']);
        self::assertSame('Renfort', $publication['neutralLines'][0]['lineName']);
        // …and as soon as it has a demand, it counts, with its own verdict: not publishable until generated. Since
        // lot L4c (D196) it no longer holds back the republication of the primary line, and it is only ever
        // published when chosen explicitly (PlanningPublicationByLineTest).
        $this->prepareLine($this->s['planningId'], [['2027-01-07', '2027-01-08']], lineIndex: 1);
        $publication = $this->api($this->client, 'GET', "/api/plannings/{$this->s['planningId']}/publication-preflight", token: $this->s['creator']);
        self::assertResponseIsSuccessful();
        self::assertTrue($publication['republishable']);
        self::assertCount(2, $publication['lines']);
        self::assertSame([], $publication['neutralLines']);
        self::assertSame([true, false], array_column($publication['lines'], 'published'));
        self::assertSame(['INCOMPLETE', 'NOT_GENERATED'], $publication['lines'][1]['blockers'], 'no member yet, never generated');
        $refused = $this->api($this->client, 'POST', "/api/plannings/{$this->s['planningId']}/republish", [], $this->s['creator']);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('no_changes', $refused['error']);
        self::assertSame('DRAFT', $this->periodNamed('Renfort')['status'], 'never published by a republication');
        $this->api($this->client, 'GET', "/api/plannings/{$this->s['planningId']}/result", token: $this->s['creator']);
        self::assertResponseIsSuccessful();
    }

    public function testALineAddedToAPlanningThatIsOverGetsItsPeriodAsBefore(): void
    {
        $this->s = $this->pilotScenario($this->client);
        self::mockTime('2027-06-15 12:00:00 UTC');
        $planning = static::getContainer()->get(PlanningRepository::class)->findOneByStableId($this->s['planningId']);
        self::assertNotNull($planning);

        $line = static::getContainer()->get(PlanningLineService::class)->addLine($planning, 'Tardive', PlanningLineType::SECONDARY);

        self::assertCount(1, $line->getPeriods());
        self::assertSame(['DRAFT', '2027-01-01', '2027-05-01', $this->onlyCycleId()], array_values(array_intersect_key($this->periodNamed('Tardive'), array_flip(['status', 'starts_at', 'ends_at', 'planning_cycle_id']))));
    }

    // --- deleting a line --------------------------------------------------------------------------

    public function testDeletingAGeneratedLineKeepsItsPeriodAndHistoryAsBefore(): void
    {
        $this->s = $this->pilotScenario($this->client);
        $this->addSecondaryLine($this->client, $this->s, ['junior@example.com']);
        $this->prepareLine($this->s['planningId'], [['2027-01-05', '2027-01-06']]);
        $this->prepareLine($this->s['planningId'], [['2027-01-07', '2027-01-08']], lineIndex: 1);
        $this->generate($this->client, $this->s);
        $period = $this->periodNamed('Juniors');
        $history = fn (): array => $this->db()->fetchNumeric(
            'SELECT (SELECT COUNT(*) FROM planning_generations WHERE planning_period_id = :p), (SELECT COUNT(*) FROM duties WHERE planning_period_id = :p), (SELECT COUNT(*) FROM duty_assignments a JOIN duties d ON d.id = a.duty_id WHERE d.planning_period_id = :p)',
            ['p' => $period['id']],
        );
        $before = $history();
        self::assertGreaterThan(0, $before[0]);
        self::assertGreaterThan(0, $before[2]);
        $juniors = $this->api($this->client, 'GET', "/api/plannings/{$this->s['planningId']}", token: $this->s['creator'])['lines'][1]['stableId'];

        $this->api($this->client, 'DELETE', "/api/plannings/{$this->s['planningId']}/lines/{$juniors}", token: $this->s['creator']);
        self::assertResponseStatusCodeSame(204);

        $after = $this->periodNamed('Juniors');
        self::assertNull($after['planning_line_id'], 'the period stays, attached to no line');
        self::assertSame($period['planning_cycle_id'], $after['planning_cycle_id']);
        self::assertSame($before, $history(), 'its generations, duties and assignments stay');
        self::assertCount(1, $this->api($this->client, 'GET', "/api/plannings/{$this->s['planningId']}", token: $this->s['creator'])['lines']);
        // D191: its duty still held and not over is never silently ignored — the audit asks for a decision.
        $held = ['orphan_current_assignments_not_over: '.$before[2]];
        $this->assertAuditClean($held);

        // The orphan neither moves with the planning nor gets in its way (extension, generation).
        $this->api($this->client, 'POST', "/api/plannings/{$this->s['planningId']}/extensions", ['endsAt' => '2027-06-01'], $this->s['creator']);
        self::assertResponseIsSuccessful();
        self::assertSame('2027-05-01', $this->periodNamed('Juniors')['ends_at']);
        $this->assertAuditClean($held);
        $generation = $this->api($this->client, 'GET', "/api/plannings/{$this->s['planningId']}/generation-preflight", token: $this->s['creator']);
        self::assertResponseIsSuccessful();
        self::assertCount(1, $generation['lines']);
    }

    public function testDeletingALineWithoutHistoryLeavesItsPeriodBehindAsBefore(): void
    {
        $this->s = $this->pilotScenario($this->client);
        $line = $this->api($this->client, 'POST', "/api/plannings/{$this->s['planningId']}/lines", ['name' => 'Renfort'], $this->s['creator']);
        self::assertResponseStatusCodeSame(201);

        $this->api($this->client, 'DELETE', "/api/plannings/{$this->s['planningId']}/lines/{$line['stableId']}", token: $this->s['creator']);
        self::assertResponseStatusCodeSame(204);
        self::assertNull($this->periodNamed('Renfort')['planning_line_id']);
        self::assertSame(0, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM planning_lines WHERE stable_id = ?', [$line['stableId']]));
        $this->assertAuditClean();
    }

    /**
     * docs/decisions.md D190: the duties of a deleted line (its period kept,
     * attached to no line) are history, never a commitment — no screen, email
     * or engine shows them to anyone any more, as before L1. The audit counts
     * those still current and not over, to be seen before any activation.
     */
    public function testTheDutiesOfADeletedLineAreHistoryNotACommitment(): void
    {
        $this->s = $this->pilotScenario($this->client);
        $teamId = $this->addSecondaryLine($this->client, $this->s, ['junior@example.com']);
        $this->addMemberAs($this->client, $this->s['creator'], $this->s['planningId'], $teamId, 'alice@example.com', 'MEMBER');
        $this->prepareLine($this->s['planningId'], [['2027-01-05', '2027-01-06']]);
        $this->prepareLine($this->s['planningId'], [['2027-01-06', '2027-01-07']], lineIndex: 1);
        // Alice is the only one free for the Juniors day (6 January), right after the Seniors day (5 January).
        $availability = static::getContainer()->get(UserAvailabilityService::class);
        $users = static::getContainer()->get(UserRepository::class);
        $availability->create($users->findOneByEmail('junior@example.com'), UserAvailabilityType::UNAVAILABLE, new \DateTimeImmutable('2027-01-05 00:00 UTC'), new \DateTimeImmutable('2027-01-08 00:00 UTC'));
        $aliceAway = $availability->create($users->findOneByEmail('alice@example.com'), UserAvailabilityType::UNAVAILABLE, new \DateTimeImmutable('2027-01-04 00:00 UTC'), new \DateTimeImmutable('2027-01-05 12:00 UTC'));
        $this->generateNow($this->client, $this->s['planningId'], $this->s['creator'], ['legalMinRestEnabled' => true, 'legalMinRestHours' => 11]);
        self::assertSame('alice@example.com', $this->currentCalendar($this->s['planningId'])['1|2027-01-06|ONCALL']);
        $seniorsDay = $this->dutyOn($this->s['planningId'], '2027-01-05');
        $email = fn (array $c): string => (string) $this->db()->fetchOne('SELECT u.email FROM planning_team_members m JOIN users u ON u.id = m.user_id WHERE m.stable_id = ?', [$c['teamMemberStableId']]);
        $this->db()->executeStatement('DELETE FROM user_availability_periods WHERE id = ?', [$aliceAway->getId()]);
        $before = $this->candidatesFor($this->client, $this->s, $seniorsDay);
        self::assertNotContains('alice@example.com', array_map($email, $before['candidates']), 'while the line exists, her 6 January day forbids the 5th (11-hour rest)');

        $juniors = $this->api($this->client, 'GET', "/api/plannings/{$this->s['planningId']}", token: $this->s['creator'])['lines'][1]['stableId'];
        $this->api($this->client, 'DELETE', "/api/plannings/{$this->s['planningId']}/lines/{$juniors}", token: $this->s['creator']);
        self::assertResponseStatusCodeSame(204);

        $audit = static::getContainer()->get(PlanningCycleAudit::class)->report();
        self::assertSame(1, $audit['invariants']['orphan_current_assignments_not_over']['count'], 'kept, current, not over — and reported');
        self::assertTrue($audit['invariants']['orphan_current_assignments_not_over']['blocking'], 'never silently ignored: the audit fails until it is dealt with');
        $after = $this->candidatesFor($this->client, $this->s, $seniorsDay);
        self::assertContains('alice@example.com', array_map($email, $after['candidates']), 'the deleted line\'s day is history: it constrains nobody any more');
    }

    public function testALineASwapRequestNamesIsNotDeleted(): void
    {
        self::mockTime('2026-12-10 08:00:00 UTC');
        $this->s = $this->swapScenario($this->client);
        $line = $this->api($this->client, 'POST', "/api/plannings/{$this->s['planningId']}/lines", ['name' => 'Renfort'], $this->s['creator']);
        self::assertResponseStatusCodeSame(201);
        $this->agreedRequest($this->client, $this->s, $this->s['alice'], '2027-01-05', 'bob@example.com', '2027-01-12');
        self::assertResponseStatusCodeSame(201);
        // Before L1 the database refused this deletion (duty_swap_requests.line_id ON DELETE RESTRICT); a swap request
        // on a secondary line is put on the new line directly here.
        $this->db()->executeStatement('UPDATE duty_swap_requests SET line_id = (SELECT id FROM planning_lines WHERE stable_id = ?)', [$line['stableId']]);

        $this->api($this->client, 'DELETE', "/api/plannings/{$this->s['planningId']}/lines/{$line['stableId']}", token: $this->s['creator']);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('line_has_history', json_decode((string) $this->client->getResponse()->getContent(), true)['error'] ?? null);
        self::assertSame(1, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM planning_lines WHERE stable_id = ?', [$line['stableId']]));
    }

    // --- swaps, "Mes gardes", calendar feed, Saturday's reminder (lot L4e, D198) -------------------

    public function testSwapsMyDutiesTheFeedAndTheReminderWorkAsBeforeWhileTheFlagIsOff(): void
    {
        self::mockTime('2026-12-10 08:00:00 UTC');
        $this->s = $this->swapScenario($this->client);
        $period = ['stableId' => (string) $this->db()->fetchOne('SELECT stable_id FROM planning_cycles WHERE id = ?', [$this->onlyCycleId()]), 'number' => 1, 'count' => 1];

        // A swap, as before: Alice's 5th against Bob's 12th.
        $request = $this->agreedRequest($this->client, $this->s, $this->s['alice'], '2027-01-05', 'bob@example.com', '2027-01-12');
        self::assertResponseStatusCodeSame(201, (string) $this->client->getResponse()->getContent());
        self::assertSame($period, $request['offered']['period']);
        $this->decide($this->client, $request['proposals'][0]['stableId'], 'accept', $this->s['bob']);
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());
        self::assertSame('bob@example.com', $this->holders($this->s)['2027-01-05']);

        // "Mes gardes": the same units, with their only period.
        $alice = $this->api($this->client, 'GET', '/api/me/duties', token: $this->s['alice'])['duties'];
        self::assertSame([['2027-01-12']], array_column($alice, 'dates'));
        self::assertSame($period, $alice[0]['period']);

        // The feed: the unit's own UID.
        $feed = $this->api($this->client, 'POST', '/api/me/calendar-feed', token: $this->s['alice'])['feed'];
        $this->client->request('GET', "/api/calendar-feeds/{$feed['token']}.ics");
        self::assertResponseIsSuccessful();
        self::assertSame(1, substr_count((string) $this->client->getResponse()->getContent(), 'UID:'.$this->dutyOn($this->s['planningId'], '2027-01-12').'@medvue'));

        // Saturday 2 January: Bob is reminded of the 5th he now holds — once.
        $tester = new CommandTester((new Application(static::$kernel))->find('app:duty-reminders:weekly'));
        $tester->execute(['--now' => '2027-01-02 08:00:00 Europe/Brussels']);
        $tester->assertCommandIsSuccessful();
        $tester->execute(['--now' => '2027-01-02 08:00:00 Europe/Brussels']);
        $bob = array_values(array_filter(self::getMailerMessages(), static fn ($m): bool => $m instanceof Email && str_starts_with((string) $m->getSubject(), 'Vos gardes de la semaine prochaine') && 'bob@example.com' === $m->getTo()[0]->getAddress()));
        self::assertCount(1, $bob);
        self::assertStringContainsString('Mardi 5 janvier', (string) $bob[0]->getTextBody());
        // docs/decisions.md D199: the swap is not republished yet — said so, the flag off as on.
        self::assertMatchesRegularExpression('/Mardi 5 janvier — [^\n]+\(changement pas encore republié\)/', (string) $bob[0]->getTextBody());
        self::assertStringContainsString('Ce rappel tient compte de changements faits depuis la dernière diffusion du planning', (string) $bob[0]->getTextBody());
    }

    public function testAMemberReadsNoDraftWhileTheFlagIsOff(): void
    {
        // docs/decisions.md D204: the read policy does not depend on the flag — a draft is no member's to read.
        $this->s = $this->pilotScenario($this->client);
        $this->prepareLine($this->s['planningId'], [['2027-01-05', '2027-01-06']]);
        self::assertSame('SUCCEEDED', $this->generateNow($this->client, $this->s['planningId'], $this->s['creator'])['status']);

        self::assertSame([], $this->api($this->client, 'GET', "/api/plannings/{$this->s['planningId']}/result", token: $this->s['alice'])['lines']);
        self::assertSame([], $this->api($this->client, 'GET', "/api/plannings/{$this->s['planningId']}/assignments", token: $this->s['alice'])['assignments']);
        self::assertCount(1, $this->api($this->client, 'GET', "/api/plannings/{$this->s['planningId']}/result", token: $this->s['creator'])['lines']);

        $this->api($this->client, 'POST', "/api/plannings/{$this->s['planningId']}/publish", [], $this->s['creator']);
        self::assertResponseIsSuccessful();
        self::assertSame(['Seniors'], array_column($this->api($this->client, 'GET', "/api/plannings/{$this->s['planningId']}/result", token: $this->s['alice'])['lines'], 'lineName'));
    }

    public function testStatisticsWorkWithoutAPeriodWhileTheFlagIsOff(): void
    {
        self::mockTime('2026-12-10 08:00:00 UTC');
        $this->s = $this->swapScenario($this->client);

        // docs/decisions.md D201: one period — no ?period needed, as before; published, so the cumulative is that period.
        $statistics = $this->api($this->client, 'GET', "/api/plannings/{$this->s['planningId']}/statistics", token: $this->s['creator']);
        self::assertResponseIsSuccessful();
        self::assertSame(1, $statistics['period']['number']);
        self::assertSame([['Seniors', 'PUBLISHED']], array_map(static fn (array $l): array => [$l['lineName'], $l['publication']], $statistics['period']['lines']));
        self::assertSame($statistics['currentPeriod']['groups'][0]['members'], $statistics['cumulative']['groups'][0]['members']);
        self::assertSame([1], $statistics['cumulative']['periodNumbers']);
        self::assertSame([], $statistics['provisional']['groups']);
        self::assertSame(['2027-01-01', '2027-05-01'], [$statistics['currentPeriod']['startsAt'], $statistics['currentPeriod']['endsAt']]);
        $totals = array_column($statistics['cumulative']['groups'][0]['members'], 'total', 'lastName');
        ksort($totals);
        self::assertSame(['Durand' => 1, 'Leroy' => 4, 'Martin' => 1], $totals, 'the holders swapScenario set (the weekend block counts its two days)');
        self::assertSame($statistics, $this->api($this->client, 'GET', "/api/plannings/{$this->s['planningId']}/statistics?period={$statistics['period']['stableId']}", token: $this->s['creator']), 'naming the only period changes nothing');
    }

    public function testExportsWorkAsBeforeWhileTheFlagIsOffWithTheirL4fRules(): void
    {
        self::mockTime('2026-12-10 08:00:00 UTC');
        $this->s = $this->swapScenario($this->client);
        $line = $this->api($this->client, 'GET', "/api/plannings/{$this->s['planningId']}", token: $this->s['creator'])['lines'][0]['stableId'];
        $body = ['format' => 'xlsx', 'title' => 'Gardes', 'from' => '2027-01-04', 'to' => '2027-01-11', 'lines' => [['stableId' => $line, 'label' => 'Seniors']]];
        $workbook = function (string $who) use ($body): array {
            $this->client->request('POST', "/api/plannings/{$this->s['planningId']}/export", server: ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$this->s[$who]], content: (string) json_encode($body));
            self::assertResponseIsSuccessful();

            return $this->readXlsx((string) $this->client->getResponse()->getContent());
        };

        // One period: no "Période" column, as before; the document says what it is (docs/decisions.md D200).
        $sheets = $workbook('admin');
        self::assertSame(['Date', 'Jour', 'Seniors'], $sheets['Planning'][0]);
        self::assertSame(['Personne', 'Date', 'Jour', 'Ligne'], $sheets['Par personne'][0]);
        self::assertContains(['Nature', 'Calendrier courant — identique à la dernière diffusion'], $sheets['Informations']);

        // A swap, not republished: the manager's export is labelled, the member's stays what was diffused.
        $request = $this->agreedRequest($this->client, $this->s, $this->s['alice'], '2027-01-05', 'bob@example.com', '2027-01-12');
        $this->decide($this->client, $request['proposals'][0]['stableId'], 'accept', $this->s['bob']);
        self::assertResponseIsSuccessful();
        $sheets = $workbook('admin');
        self::assertSame('Bob Durand', $sheets['Planning'][2][2], 'Tuesday 5 January, in force');
        self::assertContains(['Nature', 'Calendrier opérationnel — modifications non publiées possibles'], $sheets['Informations']);
        self::assertSame('Alice Martin', $workbook('alice')['Planning'][2][2], 'what was diffused');

        // The absences: the whole planning without a range, as before, and no period named.
        $this->client->request('GET', "/api/plannings/{$this->s['planningId']}/availability-export.pdf", server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->s['creator']]);
        self::assertResponseIsSuccessful();
        self::assertStringEndsWith('_2027-01-01_2027-04-30.pdf', (string) $this->client->getResponse()->headers->get('Content-Disposition'));
        $planning = static::getContainer()->get(PlanningRepository::class)->findOneByStableId($this->s['planningId']);
        self::assertNull(PlanningAbsenceExportService::scopeLabel($planning, '2027-01-01', '2027-05-01'));
    }
}
