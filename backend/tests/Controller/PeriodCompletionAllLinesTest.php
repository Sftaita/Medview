<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Tests\InheritedReinforcementTestHelpers;
use App\Tests\LineFillTestHelpers;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * docs/decisions.md D226, D228 — "Compléter la période" is about every line of
 * the period: line 1 entirely attributed, line 2 partly, lines 3 and 4 new. Real
 * generations (OR-Tools), the data read before and after.
 */
final class PeriodCompletionAllLinesTest extends WebTestCase
{
    use LineFillTestHelpers;
    use InheritedReinforcementTestHelpers;

    private const WEEK = [
        'blocks' => [['name' => 'Week-end', 'days' => ['VEN', 'SAM', 'DIM'], 'family' => 'Week-end']],
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

    private function shorten(): string
    {
        $period = $this->api($this->client, 'GET', "/api/plannings/{$this->s['planningId']}/periods", token: $this->s['creator'])['periods'][0]['stableId'];
        $this->api($this->client, 'PATCH', "/api/plannings/{$this->s['planningId']}/periods/{$period}", ['endsAt' => '2027-02-01', 'expectedEndsAt' => '2027-05-01'], $this->s['creator']);
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());

        return $period;
    }

    /** @param list<string> $emails */
    private function addLine(string $name, array $emails): string
    {
        $this->api($this->client, 'POST', "/api/plannings/{$this->s['planningId']}/lines", ['name' => $name], $this->s['creator']);
        self::assertResponseStatusCodeSame(201);
        $line = null;
        foreach ($this->api($this->client, 'GET', "/api/plannings/{$this->s['planningId']}", token: $this->s['creator'])['lines'] as $candidate) {
            if ($candidate['name'] === $name) {
                $line = $candidate;
            }
        }
        foreach ($emails as $email) {
            if ('alice@example.com' !== $email) {
                $this->userToken($this->client, $email);
            }
            $this->addMemberAs($this->client, $this->s['creator'], $this->s['planningId'], $line['team']['stableId'], $email, 'MEMBER');
        }
        $this->activate($line['stableId']);

        return $line['stableId'];
    }

    private function activate(string $line): void
    {
        $this->api($this->client, 'POST', "/api/planning-lines/{$line}/rule-set/activate", [], $this->s['creator']);
        self::assertContains($this->client->getResponse()->getStatusCode(), [200, 201]);
    }

    /** @return array<string, list<array{date: string, member: ?int, user: ?int, assignment: ?int}>> by line name */
    private function byLine(): array
    {
        $rows = $this->db()->fetchAllAssociative(<<<'SQL'
            SELECT pl.name AS line, d.local_date::text AS date, a.id AS assignment, a.team_member_id AS member, tm.user_id AS "user", a.source, a.generation_id AS generation
            FROM duties d JOIN planning_periods pp ON pp.id = d.planning_period_id JOIN planning_lines pl ON pl.id = pp.planning_line_id
            LEFT JOIN duty_assignments a ON a.duty_id = d.id AND a.current
            LEFT JOIN planning_team_members tm ON tm.id = a.team_member_id
            WHERE d.retired_at IS NULL ORDER BY d.local_date, d.id
            SQL);
        $byLine = [];
        foreach ($rows as $row) {
            $byLine[$row['line']][] = $row;
        }

        return $byLine;
    }

    private static function loadOf(array $rows): array
    {
        $load = [];
        foreach ($rows as $row) {
            if (null !== $row['member']) {
                $load[(int) $row['member']] = ($load[(int) $row['member']] ?? 0) + 1;
            }
        }

        return $load;
    }

    public function testEveryLineReceivesWhatItMissesAndNothingElseMoves(): void
    {
        $this->s = $this->pilotScenario($this->client);
        $this->shorten();
        $this->api($this->client, 'PUT', "/api/plannings/{$this->s['planningId']}/week-template", self::WEEK, $this->s['creator']);
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());
        $seniors = $this->lineIds($this->client, $this->s)[0];
        $this->activate($seniors);
        self::assertSame('SUCCEEDED', $this->fillNow($this->client, $this->s, [$seniors])['status']);

        // Line 2, generated, then one duty removed by hand.
        $juniors = $this->addLine('Juniors', ['junior1@example.com', 'junior2@example.com']);
        self::assertSame('SUCCEEDED', $this->fillNow($this->client, $this->s, [$juniors])['status']);
        $duty = $this->db()->fetchAssociative("SELECT d.stable_id, tm.stable_id AS holder FROM duties d JOIN planning_periods pp ON pp.id = d.planning_period_id JOIN planning_lines pl ON pl.id = pp.planning_line_id JOIN duty_assignments a ON a.duty_id = d.id AND a.current JOIN planning_team_members tm ON tm.id = a.team_member_id WHERE pl.name = 'Juniors' AND d.local_date = '2027-01-12'");
        $this->api($this->client, 'POST', "/api/plannings/{$this->s['planningId']}/duties/{$duty['stable_id']}/unassign", ['expectedCurrentTeamMemberStableId' => $duty['holder']], $this->s['creator']);
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());

        // Lines 3 and 4, new: they start on the general structure. Alice is also a Senior.
        $internes = $this->addLine('Internes', ['interne1@example.com', 'interne2@example.com', 'alice@example.com']);
        $externes = $this->addLine('Externes', ['externe1@example.com', 'externe2@example.com']);

        $before = $this->byLine();
        $plans = array_column($this->fillPreview($this->client, $this->s)['lines'], null, 'lineName');
        self::assertSame(['NOTHING', 'COMPLETE', 'GENERATE', 'GENERATE'], [$plans['Seniors']['action'], $plans['Juniors']['action'], $plans['Internes']['action'], $plans['Externes']['action']]);
        self::assertSame(1, $plans['Juniors']['uncoveredRequiredDutyCount']);
        self::assertSame(\count($before['Seniors']), $plans['Internes']['requiredDutyCount'], 'the same general structure');

        $job = $this->fillNow($this->client, $this->s, [$seniors, $juniors, $internes, $externes]);
        self::assertSame('SUCCEEDED', $job['status'], json_encode($job));
        $results = $this->fillLines($job);
        self::assertSame(0, $job['outcome']['modifiedExistingCount'], 'measured');
        self::assertSame(1, $results['Juniors']['addedCount']);
        self::assertSame(\count($before['Seniors']), $results['Internes']['addedCount']);
        self::assertSame(\count($before['Seniors']), $results['Externes']['addedCount']);
        self::assertSame(0, $results['Seniors']['addedCount']);
        foreach (['Juniors', 'Internes', 'Externes', 'Seniors'] as $name) {
            self::assertSame(0, $results[$name]['remainingUncoveredCount'] ?? 0, $name);
        }

        $after = $this->byLine();
        self::assertSame($before['Seniors'], $after['Seniors'], 'line 1 untouched, row by row');
        foreach ($before['Juniors'] as $i => $row) {
            if (null !== $row['assignment']) {
                self::assertSame($row, $after['Juniors'][$i], 'line 2 keeps what it holds');
            }
        }

        // Fairness per group: each line shares its duties between ITS members, separately from the others.
        foreach (['Juniors' => 2, 'Internes' => 3, 'Externes' => 2] as $name => $members) {
            $load = self::loadOf($after[$name]);
            self::assertCount($members, $load, $name.': every member of the group has duties, nobody else');
            self::assertLessThanOrEqual(4, max($load) - min($load), $name.' balanced on its own');
        }
        // Across lines: a person never holds two duties the same day (Alice, on Seniors and Internes).
        $days = [];
        foreach ($after as $rows) {
            foreach ($rows as $row) {
                if (null !== $row['user']) {
                    $key = $row['user'].'|'.$row['date'];
                    self::assertArrayNotHasKey($key, $days, 'twice the same day: '.$key);
                    $days[$key] = true;
                }
            }
        }
        // A block has one holder, on every line.
        self::assertSame(0, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM (SELECT d.group_instance_id FROM duties d JOIN duty_assignments a ON a.duty_id = d.id AND a.current WHERE d.group_instance_id IS NOT NULL GROUP BY d.group_instance_id HAVING COUNT(DISTINCT a.team_member_id) > 1) x'));

        // Nothing left: the same action has nothing to do.
        self::assertSame(['NOTHING', 'NOTHING', 'NOTHING', 'NOTHING'], array_column($this->fillPreview($this->client, $this->s)['lines'], 'action'));
    }

    public function testAPublishedLineIsLeftAloneAndNeverStopsTheOthers(): void
    {
        $this->s = $this->pilotScenario($this->client);
        $this->shorten();
        $this->api($this->client, 'PUT', "/api/plannings/{$this->s['planningId']}/week-template", self::WEEK, $this->s['creator']);
        $seniors = $this->lineIds($this->client, $this->s)[0];
        $this->activate($seniors);
        self::assertSame('SUCCEEDED', $this->fillNow($this->client, $this->s, [$seniors])['status']);
        // The line exists before the publication (a new line joins no period already published, D196): published alone.
        $externes = $this->addLine('Externes', ['externe1@example.com', 'externe2@example.com']);
        $this->api($this->client, 'POST', "/api/plannings/{$this->s['planningId']}/publish", ['lines' => [$seniors]], $this->s['creator']);
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());
        $duty = $this->db()->fetchAssociative("SELECT d.stable_id, tm.stable_id AS holder FROM duties d JOIN duty_assignments a ON a.duty_id = d.id AND a.current JOIN planning_team_members tm ON tm.id = a.team_member_id WHERE d.local_date = '2027-01-12'");
        $this->api($this->client, 'POST', "/api/plannings/{$this->s['planningId']}/duties/{$duty['stable_id']}/unassign", ['expectedCurrentTeamMemberStableId' => $duty['holder']], $this->s['creator']);
        $before = $this->byLine()['Seniors'];

        $plans = array_column($this->fillPreview($this->client, $this->s)['lines'], null, 'lineName');
        self::assertSame(['EXCLUDED', 'published', 1], [$plans['Seniors']['action'], $plans['Seniors']['reason'], $plans['Seniors']['uncoveredRequiredDutyCount']]);
        self::assertSame('GENERATE', $plans['Externes']['action']);

        // Even asked for, by a hand-made request: nothing for the published line.
        $job = $this->fillNow($this->client, $this->s, [$seniors, $externes]);
        self::assertSame('SUCCEEDED', $job['status'], json_encode($job));
        self::assertSame($before, $this->byLine()['Seniors']);
        self::assertSame(\count($before), $this->fillLines($job)['Externes']['addedCount']);
    }

    public function testAnInheritingLineFollowsItsPublishedSourceOnTheNewDatesOnly(): void
    {
        $this->s = $this->inheritedScenario($this->client);
        $period = $this->shorten();
        $this->configureFell($this->client, $this->s);
        $lines = [$this->s['primaryLineId'], $this->s['fellLineId']];
        self::assertSame('SUCCEEDED', $this->fillNow($this->client, $this->s, $lines)['status']);
        $this->api($this->client, 'POST', "/api/plannings/{$this->s['planningId']}/publish", [], $this->s['creator']);
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());
        $before = $this->fellRows();
        $beforePrimary = $this->byLine()[array_key_first($this->byLine())];

        // Two more weeks: 4 source duties (Tuesday + block), twice.
        $this->api($this->client, 'PATCH', "/api/plannings/{$this->s['planningId']}/periods/{$period}", ['endsAt' => '2027-02-15', 'expectedEndsAt' => '2027-02-01'], $this->s['creator']);
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());
        self::assertCount(\count($before), $this->fellRows(), 'a reinforcement depends on a holder: none yet');

        $plans = array_column($this->fillPreview($this->client, $this->s)['lines'], null, 'lineStableId');
        self::assertTrue($plans[$this->s['primaryLineId']]['extensionOnly']);
        self::assertSame(8, $plans[$this->s['primaryLineId']]['uncoveredRequiredDutyCount']);

        $job = $this->fillNow($this->client, $this->s, [$this->s['primaryLineId']]);
        self::assertSame('SUCCEEDED', $job['status'], json_encode($job));
        self::assertSame(0, $job['outcome']['modifiedExistingCount']);

        $primaryName = array_key_first($this->byLine());
        foreach ($beforePrimary as $i => $row) {
            self::assertSame($row, $this->byLine()[$primaryName][$i], 'the published duties of the source');
        }
        $after = $this->fellRows();
        foreach ($before as $i => $row) {
            self::assertSame($row, $after[$i], 'the published reinforcements');
        }
        $new = \array_slice($after, \count($before));
        foreach ($new as $row) {
            self::assertGreaterThanOrEqual('2027-02-01', $row['date']);
            self::assertTrue($row['sameInstants']);
            self::assertNotNull($row['holder'], 'attributed in the same run');
            self::assertNotSame($row['sourceHolder'], $row['holder'], 'never the holder of the source duty');
        }
        self::assertSame(\count($new), (int) $this->db()->fetchOne("SELECT COUNT(*) FROM duties d JOIN planning_periods pp ON pp.id = d.planning_period_id JOIN planning_lines pl ON pl.id = pp.planning_line_id WHERE pl.name = 'Fell/Ass' AND d.added_by_change_id IS NOT NULL"));
    }
}
