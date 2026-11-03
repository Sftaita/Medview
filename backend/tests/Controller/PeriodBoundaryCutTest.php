<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Tests\DutySwapTestHelpers;
use App\Tests\InheritedReinforcementTestHelpers;
use App\Tests\LineFillTestHelpers;
use App\Tests\PlanningExportTestHelpers;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * docs/decisions.md D229, D231 — a period may end inside a block when the
 * manager confirms it, and what a date change leaves behind is nowhere an active
 * duty: "Mes gardes", the iCalendar feed, the exports, swap requests, statistics.
 * Real generations (OR-Tools), the data read before and after.
 *
 * Fixture: Seniors = admin, alice, bob; Monday to Thursday alone, block
 * Friday-Saturday-Sunday; 15 January 2027 is a Friday.
 */
final class PeriodBoundaryCutTest extends WebTestCase
{
    use LineFillTestHelpers;
    use InheritedReinforcementTestHelpers;
    use DutySwapTestHelpers;
    use PlanningExportTestHelpers;

    private const STRUCTURE = [
        'blocks' => [['name' => 'Ven · Sam · Dim', 'days' => ['VEN', 'SAM', 'DIM'], 'family' => 'Week-end']],
        'solo' => ['LUN', 'MAR', 'MER', 'JEU'],
        'soloFamily' => 'Semaine',
        'excluded' => [],
    ];

    private KernelBrowser $client;

    /** @var array<string, mixed> */
    private array $s;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    private function db(): Connection
    {
        return static::getContainer()->get(Connection::class);
    }

    /** @return list<array<string, mixed>> */
    private function periods(): array
    {
        return $this->api($this->client, 'GET', "/api/plannings/{$this->s['planningId']}/periods", token: $this->s['creator'])['periods'];
    }

    /** @return array<string, mixed> */
    private function preview(string $period, string $query): array
    {
        $preview = $this->api($this->client, 'GET', "/api/plannings/{$this->s['planningId']}/periods/{$period}/date-change-preview?{$query}", token: $this->s['creator']);
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());

        return $preview;
    }

    /** Applies new dates as the screen does: with the hash of the plan it read. @return array<string, mixed> */
    private function change(string $period, string $endsAt, string $expected, bool $notify = false): array
    {
        $preview = $this->preview($period, "endsAt={$endsAt}");
        $moved = $this->api($this->client, 'PATCH', "/api/plannings/{$this->s['planningId']}/periods/{$period}", ['endsAt' => $endsAt, 'expectedEndsAt' => $expected, 'confirmation' => $preview['impactHash'], 'notify' => $notify], $this->s['creator']);
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());

        return $moved;
    }

    private function createPeriod(string $endsAt): string
    {
        $periods = $this->periods();
        $created = $this->api($this->client, 'POST', "/api/plannings/{$this->s['planningId']}/periods", ['endsAt' => $endsAt, 'previousPeriodStableId' => $periods[\count($periods) - 1]['stableId']], $this->s['creator']);
        self::assertResponseStatusCodeSame(201, (string) $this->client->getResponse()->getContent());

        return $created['stableId'];
    }

    private function structured(): string
    {
        $this->s = $this->pilotScenario($this->client);
        $period = $this->periods()[0]['stableId'];
        $this->api($this->client, 'PATCH', "/api/plannings/{$this->s['planningId']}/periods/{$period}", ['endsAt' => '2027-02-01', 'expectedEndsAt' => '2027-05-01'], $this->s['creator']);
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());
        $line = $this->lineId();
        $this->api($this->client, 'PUT', "/api/plannings/{$this->s['planningId']}/week-template", self::STRUCTURE, $this->s['creator']);
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());
        $this->api($this->client, 'POST', "/api/planning-lines/{$line}/rule-set/activate", [], $this->s['creator']);

        return $period;
    }

    private function lineId(int $index = 0): string
    {
        return $this->api($this->client, 'GET', "/api/plannings/{$this->s['planningId']}", token: $this->s['creator'])['lines'][$index]['stableId'];
    }

    private function generated(bool $publish = false): string
    {
        $period = $this->structured();
        self::assertSame('SUCCEEDED', $this->fillNow($this->client, $this->s, [$this->lineId()])['status']);
        if ($publish) {
            $this->api($this->client, 'POST', "/api/plannings/{$this->s['planningId']}/publish", [], $this->s['creator']);
            self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());
        }

        return $period;
    }

    /** @return array<string, mixed>|false the current row of a day */
    private function day(string $date, bool $retired = false): array|false
    {
        return $this->db()->fetchAssociative(
            'SELECT d.id, d.stable_id, d.group_instance_id, a.id AS assignment, a.team_member_id AS member, u.email FROM duties d LEFT JOIN duty_assignments a ON a.duty_id = d.id AND a.current LEFT JOIN planning_team_members tm ON tm.id = a.team_member_id LEFT JOIN users u ON u.id = tm.user_id WHERE d.local_date = ? AND '.($retired ? 'd.retired_at IS NOT NULL' : 'd.retired_at IS NULL'),
            [$date],
        );
    }

    private function tokenOf(string $email): string
    {
        return $this->s[strstr($email, '@', true)];
    }

    /** "Compléter la période": every line given, on a planning with several periods. */
    private function completePeriod(string $period, array $lines): void
    {
        $job = $this->api($this->client, 'POST', "/api/plannings/{$this->s['planningId']}/fill?period={$period}", ['lines' => $lines], $this->s['creator']);
        self::assertResponseStatusCodeSame(202, json_encode($job));
        self::assertSame(1, $this->runQueuedPlanningJobs());
    }

    private function count0(string $sql): int
    {
        return (int) $this->db()->fetchOne($sql);
    }

    // --- 1. a boundary inside a block: warned, confirmed, coherent ---------------------------------

    public function testEndingInsideABlockWarnsProposesAndKeepsTheDaysBeforeTheBoundary(): void
    {
        $period = $this->generated(publish: true);
        $friday = $this->day('2027-01-15');
        $saturday = $this->day('2027-01-16');
        self::assertSame($friday['member'], $saturday['member'], 'one holder before');

        // In the calendar: possible, with a warning — never refused.
        $options = $this->api($this->client, 'GET', "/api/plannings/{$this->s['planningId']}/periods/date-options?period={$period}&edge=end&from=2027-01-14&to=2027-01-17", token: $this->s['creator']);
        $byDay = array_column($options['days'], null, 'date');
        self::assertTrue($byDay['2027-01-15']['allowed']);
        self::assertSame('period_boundary_splits_block', $byDay['2027-01-15']['warnings'][0]['code']);
        self::assertSame([], $byDay['2027-01-14']['warnings']);
        self::assertSame([], $byDay['2027-01-17']['warnings']);

        $preview = $this->preview($period, 'endsAt=2027-01-16');
        self::assertTrue($preview['allowed']);
        self::assertTrue($preview['confirmationRequired']);
        self::assertSame(['before' => '2027-01-14', 'after' => '2027-01-17'], $preview['alternatives']['end'], 'the nearest dates that keep it whole');
        self::assertSame('Ven · Sam · Dim', $preview['blockCuts'][0]['blockName']);
        $unit = $preview['retiredUnits'][0];
        self::assertSame([['2027-01-16', '2027-01-17'], ['2027-01-15']], [$unit['dates'], $unit['keptDates']]);

        // Never without the confirmation.
        $this->api($this->client, 'PATCH', "/api/plannings/{$this->s['planningId']}/periods/{$period}", ['endsAt' => '2027-01-16', 'expectedEndsAt' => '2027-02-01'], $this->s['creator']);
        self::assertResponseStatusCodeSame(409);

        $this->change($period, '2027-01-16', '2027-02-01');
        $kept = $this->day('2027-01-15');
        self::assertSame([$friday['id'], $friday['assignment'], $friday['member']], [$kept['id'], $kept['assignment'], $kept['member']], 'Friday: the same row, the same holder');
        self::assertFalse($this->day('2027-01-16'), 'no longer in the calendar');
        self::assertNotFalse($this->day('2027-01-16', retired: true), 'kept, retired');
        self::assertSame(1, $this->count0('SELECT COUNT(*) FROM planning_cycles WHERE end_cut_confirmed'), 'the decision is recorded');

        // The rest of the block is one unit on its own: a reassignment moves Friday alone.
        $this->api($this->client, 'POST', "/api/plannings/{$this->s['planningId']}/duties/{$kept['stable_id']}/unassign", ['expectedCurrentTeamMemberStableId' => $this->db()->fetchOne('SELECT stable_id FROM planning_team_members WHERE id = ?', [$kept['member']])], $this->s['creator']);
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());
        self::assertSame(1, $this->count0("SELECT COUNT(*) FROM duty_assignment_events e JOIN duties d ON d.id = e.duty_id WHERE d.local_date = '2027-01-15' AND e.new_assignment_id IS NULL"));
    }

    public function testConfirmingAndWarningTellsTheHolderOnlyOfTheDaysLost(): void
    {
        $period = $this->generated(publish: true);
        $holder = $this->day('2027-01-29')['email'];
        $before = $this->count0('SELECT COUNT(*) FROM planning_publications');

        $moved = $this->change($period, '2027-01-30', '2027-02-01', notify: true); // Friday 29 stays, Saturday 30 and Sunday 31 leave
        self::assertSame(1, $moved['republished']);
        self::assertSame($before + 1, $this->count0('SELECT COUNT(*) FROM planning_publications'));

        $changes = $this->db()->fetchOne('SELECT n.changes FROM planning_publication_notifications n JOIN users u ON u.id = n.user_id WHERE n.publication_id = (SELECT MAX(id) FROM planning_publications) AND u.email = ?', [$holder]);
        self::assertNotFalse($changes, 'the holder is told');
        self::assertSame(['2027-01-30', '2027-01-31'], json_decode((string) $changes, true)[0]['dates']);
        self::assertSame(0, $this->count0("SELECT COUNT(*) FROM planning_publication_entries e JOIN duties d ON d.id = e.duty_id WHERE e.publication_id = (SELECT MAX(id) FROM planning_publications) AND d.local_date >= '2027-01-30'"));
        self::assertSame(1, $this->count0("SELECT COUNT(*) FROM planning_publication_entries e JOIN duties d ON d.id = e.duty_id WHERE e.publication_id = (SELECT MAX(id) FROM planning_publications) AND d.local_date = '2027-01-29' AND e.team_member_id IS NOT NULL"));
    }

    public function testTheOtherSideOfTheCutIsGeneratedInTheNextPeriodAsOneUnit(): void
    {
        $period1 = $this->generated();
        $period2 = $this->createPeriod('2027-03-01');
        $this->change($period1, '2027-01-16', '2027-02-01');
        self::assertSame('2027-01-16', $this->periods()[1]['startsAt']);

        $this->completePeriod($period2, [$this->lineId()]);

        $saturday = $this->day('2027-01-16');
        $sunday = $this->day('2027-01-17');
        self::assertNotNull($saturday['member'], 'the next period covers them');
        self::assertSame($saturday['group_instance_id'], $sunday['group_instance_id'], 'one unit');
        self::assertSame($saturday['member'], $sunday['member'], 'one holder');
        self::assertNotSame($saturday['group_instance_id'], $this->day('2027-01-15')['group_instance_id']);
        // Ordinary blocks are as atomic as ever, everywhere else.
        self::assertSame(0, $this->count0('SELECT COUNT(*) FROM (SELECT d.group_instance_id FROM duties d JOIN duty_assignments a ON a.duty_id = d.id AND a.current WHERE d.retired_at IS NULL AND d.group_instance_id IS NOT NULL GROUP BY 1 HAVING COUNT(DISTINCT a.team_member_id) > 1) x'));
        self::assertSame(3, $this->count0("SELECT COUNT(*) FROM duties d WHERE d.retired_at IS NULL AND d.local_date BETWEEN '2027-01-22' AND '2027-01-24' AND d.group_instance_id = (SELECT group_instance_id FROM duties WHERE local_date = '2027-01-22' AND retired_at IS NULL)"));
    }

    public function testAPeriodNeverGeneratedKeepsTheFirstDaysOfACutBlockAtItsGeneration(): void
    {
        $period = $this->structured();
        $preview = $this->preview($period, 'endsAt=2027-01-16');
        self::assertTrue($preview['confirmationRequired'], 'a cut is always a decision, even with nothing generated');
        self::assertSame([], $preview['retiredUnits']);
        $this->change($period, '2027-01-16', '2027-02-01');

        self::assertSame('SUCCEEDED', $this->fillNow($this->client, $this->s, [$this->lineId()])['status']);
        self::assertNotNull($this->day('2027-01-15')['member'], 'Friday exists and is attributed');
        self::assertFalse($this->day('2027-01-16'));
        self::assertSame(3, $this->count0("SELECT COUNT(*) FROM duties WHERE local_date BETWEEN '2027-01-08' AND '2027-01-10' AND group_instance_id = (SELECT group_instance_id FROM duties WHERE local_date = '2027-01-08')"), 'the blocks before are whole');
    }

    public function testExtendingAgainAddsTheRestOfTheBlockAndMovingTheBoundaryForgetsTheCut(): void
    {
        $period = $this->generated();
        $friday = $this->day('2027-01-15');
        $this->change($period, '2027-01-16', '2027-02-01');
        self::assertSame(1, $this->count0('SELECT COUNT(*) FROM planning_cycles WHERE end_cut_confirmed'));

        $moved = $this->change($period, '2027-01-18', '2027-01-16');
        self::assertSame(2, $moved['addedDutyCount'], 'Saturday and Sunday, new');
        self::assertSame(0, $this->count0('SELECT COUNT(*) FROM planning_cycles WHERE end_cut_confirmed'), 'this boundary cuts nothing');
        self::assertSame($friday['assignment'], $this->day('2027-01-15')['assignment'], 'Friday untouched');
        self::assertNull($this->day('2027-01-16')['member']);
        self::assertSame($this->day('2027-01-16')['group_instance_id'], $this->day('2027-01-17')['group_instance_id']);
    }

    // --- 2. a line added to a period with a published line ------------------------------------------

    public function testANewLineJoinsAPublishedPeriodIsCompletedAndPublishedOnItsOwn(): void
    {
        $this->generated(publish: true);
        $entries = $this->db()->fetchOne("SELECT md5(string_agg(e.id||':'||e.duty_id||':'||e.team_member_id, '|' ORDER BY e.id)) FROM planning_publication_entries e");
        $assignments = $this->db()->fetchOne("SELECT md5(string_agg(a.id||':'||a.duty_id||':'||a.team_member_id||':'||a.source||':'||a.current, '|' ORDER BY a.id)) FROM duty_assignments a");
        $pdf = $this->db()->fetchOne("SELECT md5(string_agg(d.id::text||d.sha256, '|' ORDER BY d.id)) FROM planning_publication_documents d");
        $notifications = $this->count0('SELECT COUNT(*) FROM planning_publication_notifications');

        $this->api($this->client, 'POST', "/api/plannings/{$this->s['planningId']}/lines", ['name' => 'Externes'], $this->s['creator']);
        self::assertResponseStatusCodeSame(201, (string) $this->client->getResponse()->getContent());
        $detail = $this->api($this->client, 'GET', "/api/plannings/{$this->s['planningId']}", token: $this->s['creator']);
        $line = $detail['lines'][1];
        foreach (['externe1@example.com', 'externe2@example.com'] as $email) {
            $this->userToken($this->client, $email);
            $this->addMemberAs($this->client, $this->s['creator'], $this->s['planningId'], $line['team']['stableId'], $email, 'MEMBER');
        }
        $this->api($this->client, 'POST', "/api/planning-lines/{$line['stableId']}/rule-set/activate", [], $this->s['creator']);

        $plans = array_column($this->fillPreview($this->client, $this->s)['lines'], null, 'lineName');
        self::assertSame('GENERATE', $plans['Externes']['action']);
        self::assertSame('EXCLUDED', $plans['Seniors']['action']);
        $job = $this->fillNow($this->client, $this->s, [$line['stableId']]);
        self::assertSame('SUCCEEDED', $job['status'], json_encode($job));
        self::assertSame(0, $job['outcome']['modifiedExistingCount']);

        // Not a row of what was published moved.
        self::assertSame($entries, $this->db()->fetchOne("SELECT md5(string_agg(e.id||':'||e.duty_id||':'||e.team_member_id, '|' ORDER BY e.id)) FROM planning_publication_entries e"));
        self::assertSame($pdf, $this->db()->fetchOne("SELECT md5(string_agg(d.id::text||d.sha256, '|' ORDER BY d.id)) FROM planning_publication_documents d"));
        self::assertSame($assignments, $this->db()->fetchOne("SELECT md5(string_agg(a.id||':'||a.duty_id||':'||a.team_member_id||':'||a.source||':'||a.current, '|' ORDER BY a.id)) FROM duty_assignments a JOIN duties d ON d.id = a.duty_id JOIN planning_periods pp ON pp.id = d.planning_period_id JOIN planning_lines pl ON pl.id = pp.planning_line_id WHERE pl.name = 'Seniors'"));

        // Published on its own, told only to its own members.
        $this->api($this->client, 'POST', "/api/plannings/{$this->s['planningId']}/publish", ['lines' => [$line['stableId']]], $this->s['creator']);
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());
        self::assertSame('FIRST_FOR_LINE', $this->db()->fetchOne('SELECT kind FROM planning_publication_lines ORDER BY id DESC LIMIT 1'));
        $told = $this->db()->fetchFirstColumn('SELECT u.email FROM planning_publication_notifications n JOIN users u ON u.id = n.user_id WHERE n.publication_id = (SELECT MAX(id) FROM planning_publications) ORDER BY 1');
        self::assertSame(['externe1@example.com', 'externe2@example.com'], $told);
        self::assertSame($notifications + 2, $this->count0('SELECT COUNT(*) FROM planning_publication_notifications'));
    }

    // --- 3. a retired duty is nowhere an active duty ------------------------------------------------

    public function testARetiredDutyLeavesMyDutiesTheFeedTheExportsAndTheStatistics(): void
    {
        $period = $this->generated(publish: true);
        $holder = $this->day('2027-01-29')['email'];
        self::assertContains(['2027-01-29', '2027-01-30', '2027-01-31'], array_column($this->myDuties($holder), 'dates'), 'before: the whole block');

        $feed = $this->api($this->client, 'POST', '/api/me/calendar-feed', token: $this->tokenOf($holder))['feed']['token'];
        $this->client->request('GET', "/api/calendar-feeds/{$feed}.ics");
        self::assertStringContainsString('20270129', (string) $this->client->getResponse()->getContent());

        // Period ends Friday 29, inside that block; one week later would have been 5 February.
        $this->change($period, '2027-01-30', '2027-02-01');

        $dates = array_column($this->myDuties($holder), 'dates');
        self::assertContains(['2027-01-29'], $dates, 'the day kept, alone');
        foreach ($dates as $unit) {
            self::assertEmpty(array_intersect($unit, ['2027-01-30', '2027-01-31']), 'a retired day');
        }

        $this->client->request('GET', "/api/calendar-feeds/{$feed}.ics");
        $ics = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('DTSTART;VALUE=DATE:20270129', $ics);
        self::assertStringNotContainsString('DTSTART;VALUE=DATE:20270130', $ics);
        self::assertStringNotContainsString('DTSTART;VALUE=DATE:20270131', $ics);

        $this->client->request('POST', "/api/plannings/{$this->s['planningId']}/export", server: $this->bearer($this->s['creator']), content: (string) json_encode([
            'format' => 'xlsx',
            'title' => 'Gardes',
            'lines' => [['stableId' => $this->lineId(), 'label' => 'Seniors']],
        ]));
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());
        $sheets = $this->readXlsx((string) $this->client->getResponse()->getContent());
        $days = array_filter(array_map(static fn (array $row): ?string => ($row[0] ?? null) instanceof \DateTimeInterface ? $row[0]->format('Y-m-d') : null, $sheets['Planning']));
        self::assertContains('2027-01-29', $days);
        self::assertNotContains('2027-01-30', $days, 'outside the period, and retired');

        $result = $this->api($this->client, 'GET', "/api/plannings/{$this->s['planningId']}/result?period={$period}", token: $this->s['creator']);
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());
        self::assertStringNotContainsString('"date":"2027-01-30"', (string) json_encode($result));
        self::assertStringNotContainsString('"date":"2027-01-31"', (string) json_encode($result));
        self::assertStringContainsString('"date":"2027-01-29"', (string) json_encode($result));
    }

    /** @return list<array<string, mixed>> */
    private function myDuties(string $email): array
    {
        $body = $this->api($this->client, 'GET', '/api/me/duties', token: $this->tokenOf($email));
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());

        return $body['duties'];
    }

    public function testASwapRequestOnARetiredDutyIsClosedAndTheOtherOnesStay(): void
    {
        $this->s = $this->swapScenario($this->client);
        $period = $this->periods()[0]['stableId'];
        $offeredAway = $this->agreedRequest($this->client, $this->s, $this->tokenOf('admin@example.com'), '2027-01-19', 'bob@example.com', '2027-01-12');
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());
        $kept = $this->agreedRequest($this->client, $this->s, $this->tokenOf('alice@example.com'), '2027-01-05', 'admin@example.com', '2027-01-06');
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());

        $preview = $this->preview($period, 'endsAt=2027-01-15');
        self::assertSame(1, $preview['swapRequestCount'], 'said before confirming');
        $this->change($period, '2027-01-15', '2027-05-01');

        self::assertSame('OBSOLETE', $this->requestDetail($this->client, $offeredAway['stableId'], $this->tokenOf('admin@example.com'))['status']);
        self::assertSame('OPEN', $this->requestDetail($this->client, $kept['stableId'], $this->tokenOf('alice@example.com'))['status']);

        $rows = $this->db()->fetchOne("SELECT md5(string_agg(id||':'||duty_id||':'||team_member_id, '|' ORDER BY id)) FROM duty_assignments WHERE current");
        $proposal = $this->requestDetail($this->client, $offeredAway['stableId'], $this->tokenOf('admin@example.com'))['proposals'][0]['stableId'];
        $this->decide($this->client, $proposal, 'accept', $this->tokenOf('bob@example.com'));
        self::assertGreaterThanOrEqual(400, $this->client->getResponse()->getStatusCode(), 'it can no longer conclude');
        self::assertSame($rows, $this->db()->fetchOne("SELECT md5(string_agg(id||':'||duty_id||':'||team_member_id, '|' ORDER BY id)) FROM duty_assignments WHERE current"));
    }

    public function testAGeneratedNextPeriodBothLinesAndInheritedBlocksMoveTogether(): void
    {
        $this->s = $this->inheritedScenario($this->client);
        $period1 = $this->periods()[0]['stableId'];
        $this->api($this->client, 'PATCH', "/api/plannings/{$this->s['planningId']}/periods/{$period1}", ['endsAt' => '2027-02-01', 'expectedEndsAt' => '2027-05-01'], $this->s['creator']);
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());
        $this->configureFell($this->client, $this->s);
        $lines = [$this->s['primaryLineId'], $this->s['fellLineId']];
        $period2 = $this->createPeriod('2027-03-01');
        $this->completePeriod($period1, $lines);
        $this->completePeriod($period2, $lines);
        $beforeRows = $this->fellRows();
        self::assertNotEmpty(array_filter($beforeRows, static fn (array $row): bool => $row['date'] >= '2027-02-01'), 'period 2 has reinforcements');

        // Period 1 takes the first week of generated period 2, and ends inside the following Friday's block.
        $target = '2027-02-06'; // last day Friday 5 February
        $preview = $this->preview($period1, "endsAt={$target}");
        self::assertTrue($preview['confirmationRequired']);
        self::assertTrue($preview['changes'][1]['generated']);
        self::assertSame('2027-02-06', $preview['changes'][1]['to']['startsAt']);
        $names = array_unique(array_column($preview['retiredUnits'], 'line'));
        sort($names);
        self::assertSame(['Fell/Ass', 'Seniors'], array_values($names), 'both lines are listed');
        $this->change($period1, $target, '2027-02-01');

        // No duty of period 2 before its new start, on either line; each kept day still has its holder.
        self::assertSame(0, $this->count0("SELECT COUNT(*) FROM duties d JOIN planning_periods pp ON pp.id = d.planning_period_id JOIN planning_cycles c ON c.id = pp.planning_cycle_id WHERE c.number = 2 AND d.retired_at IS NULL AND d.local_date < '2027-02-06'"));
        self::assertSame(0, $this->count0('SELECT COUNT(*) FROM duty_assignments a JOIN duties d ON d.id = a.duty_id WHERE a.current AND d.retired_at IS NOT NULL'));
        // A reinforcement never outlives its source: it is never left active on a retired source duty.
        self::assertSame(0, $this->count0('SELECT COUNT(*) FROM duties d JOIN duties s ON s.id = d.coverage_source_id WHERE d.retired_at IS NULL AND s.retired_at IS NOT NULL'));
        // A kept reinforcement block has one holder.
        self::assertSame(0, $this->count0('SELECT COUNT(*) FROM (SELECT d.group_instance_id FROM duties d JOIN duty_assignments a ON a.duty_id = d.id AND a.current WHERE d.retired_at IS NULL AND d.group_instance_id IS NOT NULL GROUP BY 1 HAVING COUNT(DISTINCT a.team_member_id) > 1) x'));

        // "Compléter la période 1" brings period 1's new days, source then reinforcements, nothing else moves.
        $kept = $this->db()->fetchAllAssociative('SELECT id, duty_id, team_member_id FROM duty_assignments WHERE current ORDER BY id');
        $this->completePeriod($period1, $lines);
        $after = $this->db()->fetchAllAssociative('SELECT id, duty_id, team_member_id FROM duty_assignments WHERE current ORDER BY id');
        self::assertSame($kept, array_slice($after, 0, \count($kept)), 'not an existing assignment moved');
        self::assertGreaterThan(\count($kept), \count($after));
    }

    // --- 4. collections and preparation: seen before, coherent after -------------------------------

    public function testACollectionPeopleAnsweredIsReducedOnlyAfterItsImpactWasConfirmed(): void
    {
        $this->s = $this->pilotScenario($this->client);
        $period = $this->periods()[0]['stableId'];
        $this->api($this->client, 'POST', '/api/me/calendar', ['type' => 'UNAVAILABLE', 'startsAt' => '2027-03-10T00:00:00+01:00', 'endsAt' => '2027-03-12T00:00:00+01:00'], $this->s['alice']);
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());
        $sent = $this->api($this->client, 'POST', "/api/plannings/{$this->s['planningId']}/reminders/participants", [], $this->s['creator']);
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());
        self::assertGreaterThan(0, $sent['sentCount']);
        $absences = $this->count0('SELECT COUNT(*) FROM user_availability_periods');

        $preview = $this->preview($period, 'endsAt=2027-03-01');
        self::assertTrue($preview['confirmationRequired'], 'people answered: seen and confirmed');
        $collection = $preview['collections'][0];
        self::assertSame('reduced', $collection['kind']);
        self::assertTrue($collection['open']);
        self::assertSame(3, $collection['expected']);
        self::assertSame(1, $collection['responses']);
        self::assertGreaterThan(0, $collection['messagesSent']);
        self::assertSame(['2027-01-01', '2027-02-28'], [$collection['newStartsAt'], $collection['newLastDay']]);

        $this->api($this->client, 'PATCH', "/api/plannings/{$this->s['planningId']}/periods/{$period}", ['endsAt' => '2027-03-01', 'expectedEndsAt' => '2027-05-01'], $this->s['creator']);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('2027-05-01', $this->db()->fetchOne('SELECT ends_at FROM availability_collections'), 'nothing moved');

        $this->change($period, '2027-03-01', '2027-05-01');
        self::assertSame(['2027-03-01', 'OPEN'], [$this->db()->fetchOne('SELECT ends_at FROM availability_collections'), $this->db()->fetchOne('SELECT status FROM availability_collections')], 'reduced, never closed');
        self::assertSame($absences, $this->count0('SELECT COUNT(*) FROM user_availability_periods'), 'nobody loses a declared absence');
        self::assertSame(1, $this->count0('SELECT COUNT(*) FROM availability_collection_responses WHERE last_availability_change_at IS NOT NULL'), 'the answers stay');

        // Growing again: the collection stays as it was announced, and the days it does not cover are said.
        $grow = $this->preview($period, 'endsAt=2027-04-01');
        self::assertSame(['not_extended', 31], [$grow['collections'][0]['kind'], $grow['collections'][0]['uncoveredAddedDays']]);
    }

    public function testAnIntactCollectionFollowsWithoutAsking(): void
    {
        $this->s = $this->pilotScenario($this->client);
        $period = $this->periods()[0]['stableId'];
        $preview = $this->preview($period, 'endsAt=2027-03-01');
        self::assertFalse($preview['confirmationRequired']);
        self::assertSame(['follows', 0], [$preview['collections'][0]['kind'], $preview['collections'][0]['responses']]);
    }

    public function testThePreparationOfAShortenedPeriodStaysInsideItAndWasSeenBefore(): void
    {
        $this->s = $this->pilotScenario($this->client);
        $period1 = $this->periods()[0]['stableId'];
        $period2 = $this->createPeriod('2027-08-01');
        $body = ['announceOn' => '2027-07-10', 'targetGenerationOn' => '2027-07-25', 'declarationDeadlineOn' => '2027-07-24', 'reminders' => [['value' => 2, 'unit' => 'DAY'], ['value' => 1, 'unit' => 'WEEK']]];
        $saved = $this->api($this->client, 'PUT', "/api/plannings/{$this->s['planningId']}/periods/{$period2}/preparation", $body, $this->s['creator']);
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());
        self::assertSame('2027-07-25', $saved['targetGenerationOn']);

        // Growing or moving only its start changes nothing planned.
        self::assertSame([], $this->preview($period1, 'endsAt=2027-05-08')['preparation']);

        // Ends on 30 June (exclusive 1 July): everything planned falls after it.
        $preview = $this->preview($period2, 'endsAt=2027-07-01');
        self::assertTrue($preview['confirmationRequired'], 'seen before confirming');
        $byKind = [];
        foreach ($preview['preparation'] as $change) {
            $byKind[$change['kind']][] = [$change['from'], $change['to']];
        }
        self::assertSame([['2027-07-10', null]], $byKind['announcement']);
        self::assertSame([['2027-07-25', '2027-06-30']], $byKind['target']);
        self::assertSame([['2027-07-24', '2027-06-30']], $byKind['deadline']);
        sort($byKind['reminder']);
        self::assertSame([['2027-07-18', '2027-06-23'], ['2027-07-23', '2027-06-28']], $byKind['reminder']);

        $this->change($period2, '2027-07-01', '2027-08-01');
        $view = $this->api($this->client, 'GET', "/api/plannings/{$this->s['planningId']}/periods/{$period2}/preparation", token: $this->s['creator']);
        self::assertSame('2027-06-30', $view['targetGenerationOn']);
        self::assertSame('2027-06-30', $view['declarationDeadlineOn']);
        self::assertSame('2027-06-30', $view['period']['lastDay'] ?? '2027-06-30');
        self::assertSame(['2027-06-23', '2027-06-28'], array_values(array_map(static fn (array $r): string => $r['dueOn'], array_filter($view['reminders'], static fn (array $r): bool => 'scheduled' === $r['state']))));
        // The panel measures nothing against an announcement that no longer exists; the journal keeps what was planned.
        self::assertNull($view['announcement']['on']);
        self::assertNull($this->db()->fetchOne('SELECT announcement_state FROM period_preparations'));
        self::assertStringContainsString('"kind": "announcement"', (string) $this->db()->fetchOne('SELECT impact FROM period_date_changes ORDER BY id DESC LIMIT 1'));
        $saved = $this->api($this->client, 'PUT', "/api/plannings/{$this->s['planningId']}/periods/{$period2}/preparation", ['announceOn' => null, 'targetGenerationOn' => '2027-06-30', 'declarationDeadlineOn' => '2027-06-30', 'reminders' => array_values(array_map(static fn (array $r): array => ['stableId' => $r['stableId'], 'value' => $r['value'], 'unit' => $r['unit']], $view['reminders']))], $this->s['creator']);
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());
        self::assertSame('2027-06-30', $saved['targetGenerationOn'], 'what stays is coherent: the server accepts it as it is');
    }
}
