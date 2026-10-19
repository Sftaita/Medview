<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Repository\PlanningLineRepository;
use App\Repository\PlanningRepository;
use App\Service\PlanningRuleSetService;
use App\Tests\CalendarWorkflowTestHelpers;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Statistics of weekend blocks across two periods, lot L5 (docs/decisions.md
 * D201), with the D192 historical case: period 1 ends (exclusive) on Sunday
 * 2 May 2027, so the "Ven · Sam · Dim" block of 30 April – 2 May leaves a
 * one-day remainder (Sunday 2 May) in period 2. D193: that remainder is an
 * occurrence of its family for the solver, but the statistics count its
 * real day and real load only — one day, never three.
 *
 * Every scope is checked against an independent SQL recount of the current
 * assignments (weekday, family by day, real workload, total, per
 * membership), never against the service's own code.
 *
 * Fixture (pilotScenario): weekly structure "Ven · Sam · Dim" (family
 * « Week End ») + Monday-Thursday standalone (« Jours de semaine »); period 1
 * generated and published; period 2 (→ 2 August) generated with real
 * OR-Tools, then published.
 */
final class PlanningCycleStatisticsBlocksTest extends WebTestCase
{
    use CalendarWorkflowTestHelpers;

    private const STRUCTURE = [
        'blocks' => [['name' => 'Ven · Sam · Dim', 'days' => ['VEN', 'SAM', 'DIM'], 'family' => 'Week End']],
        'solo' => ['LUN', 'MAR', 'MER', 'JEU'],
        'soloFamily' => 'Jours de semaine',
        'excluded' => [],
    ];
    private const WEEKDAYS = ['MON', 'TUE', 'WED', 'THU', 'FRI', 'SAT', 'SUN'];

    private KernelBrowser $client;

    /** @var array<string, mixed> */
    private array $s;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->s = $this->pilotScenario($this->client);
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

    /** Period 1 ends on Sunday 2 May (exclusive), generated with the structure and published; period 2 created with the historical cut accepted. */
    private function scenario(): void
    {
        $period = $this->periods()[0];
        $this->api($this->client, 'PATCH', "/api/plannings/{$this->s['planningId']}/periods/{$period['stableId']}", ['endsAt' => '2027-05-02', 'expectedEndsAt' => $period['endsAt']], $this->s['creator']);
        self::assertResponseIsSuccessful();
        $line = $this->api($this->client, 'GET', "/api/plannings/{$this->s['planningId']}", token: $this->s['creator'])['lines'][0]['stableId'];
        $this->api($this->client, 'PUT', "/api/planning-lines/{$line}/week-structure", self::STRUCTURE, $this->s['creator']);
        self::assertResponseIsSuccessful();
        $planning = static::getContainer()->get(PlanningRepository::class)->findOneByStableId($this->s['planningId']);
        $this->activateRuleSet(static::getContainer()->get(PlanningRuleSetService::class), static::getContainer()->get(PlanningLineRepository::class)->findByPlanning($planning)[0]->getPlanningTeam());
        self::assertSame('SUCCEEDED', $this->generateNow($this->client, $this->s['planningId'], $this->s['creator'])['status']);
        $this->api($this->client, 'POST', "/api/plannings/{$this->s['planningId']}/publish", [], $this->s['creator']);
        self::assertResponseIsSuccessful();

        $periods = $this->periods();
        $this->api($this->client, 'POST', "/api/plannings/{$this->s['planningId']}/periods", ['endsAt' => '2027-08-02', 'previousPeriodStableId' => $periods[0]['stableId'], 'acceptHistoricalBlockCuts' => true], $this->s['creator']);
        self::assertResponseStatusCodeSame(201, (string) $this->client->getResponse()->getContent());
        $job = $this->generateNow($this->client, $this->s['planningId'], $this->s['creator'], ['period' => $this->periods()[1]['stableId']]);
        self::assertSame('SUCCEEDED', $job['status']);
    }

    /** @return array<string, mixed> */
    private function statistics(int $number): array
    {
        $statistics = $this->api($this->client, 'GET', "/api/plannings/{$this->s['planningId']}/statistics?period={$this->periods()[$number - 1]['stableId']}", token: $this->s['creator']);
        self::assertResponseIsSuccessful();

        return $statistics;
    }

    /**
     * The independent recount: current assignments of the given periods, per membership.
     *
     * @param list<int> $numbers
     *
     * @return array<string, array{total: int, weekdays: array<string, int>, families: array<string, int>, weightedLoad: float}>
     */
    private function recount(array $numbers): array
    {
        $rows = $this->db()->fetchAllAssociative(<<<'SQL'
            SELECT tm.stable_id AS member, EXTRACT(ISODOW FROM d.local_date)::int AS dow, COALESCE(f.name, '') AS family, dt.workload_value::float AS weight
            FROM duty_assignments a
            JOIN duties d ON d.id = a.duty_id
            JOIN duty_types dt ON dt.id = d.duty_type_id
            LEFT JOIN duty_patterns p ON p.id = d.pattern_id LEFT JOIN allocation_families f ON f.id = p.family_id
            JOIN planning_periods pp ON pp.id = d.planning_period_id JOIN planning_cycles c ON c.id = pp.planning_cycle_id
            JOIN planning_team_members tm ON tm.id = a.team_member_id
            WHERE a.current AND c.number IN (?)
        SQL, [$numbers], [\Doctrine\DBAL\ArrayParameterType::INTEGER]);
        $byMember = [];
        foreach ($rows as $row) {
            $byMember[$row['member']] ??= ['total' => 0, 'weekdays' => array_fill_keys(self::WEEKDAYS, 0), 'families' => [], 'weightedLoad' => 0.0];
            ++$byMember[$row['member']]['total'];
            ++$byMember[$row['member']]['weekdays'][self::WEEKDAYS[$row['dow'] - 1]];
            $byMember[$row['member']]['families'][$row['family']] = ($byMember[$row['member']]['families'][$row['family']] ?? 0) + 1;
            $byMember[$row['member']]['weightedLoad'] += (float) $row['weight'];
        }
        ksort($byMember);

        return $byMember;
    }

    /**
     * @param array<string, mixed> $scope
     *
     * @return array<string, array{total: int, weekdays: array<string, int>, families: array<string, int>, weightedLoad: float}>
     */
    private function served(array $scope): array
    {
        $byMember = [];
        foreach ($scope['groups'] as $group) {
            foreach ($group['members'] as $row) {
                self::assertArrayNotHasKey($row['teamMemberStableId'], $byMember, 'one row per membership');
                $byMember[$row['teamMemberStableId']] = [
                    'total' => $row['total'],
                    'weekdays' => $row['countsByWeekday'],
                    'families' => array_filter($row['countsByFamily']),
                    'weightedLoad' => round((float) $row['weightedLoad'], 2),
                ];
            }
        }
        ksort($byMember);

        return $byMember;
    }

    /**
     * @param array<string, array{total: int, weekdays: array<string, int>, families: array<string, int>, weightedLoad: float}> $recount
     *
     * @return array<string, array{total: int, weekdays: array<string, int>, families: array<string, int>, weightedLoad: float}>
     */
    private function normalized(array $recount): array
    {
        foreach ($recount as &$row) {
            ksort($row['families']);
            $row['weightedLoad'] = round($row['weightedLoad'], 2);
        }
        unset($row);
        $served = $recount;

        return $served;
    }

    /** @param array<string, array{families: array<string, int>}> $rows */
    private function sortedFamilies(array $rows): array
    {
        foreach ($rows as &$row) {
            ksort($row['families']);
        }

        return $rows;
    }

    public function testEveryScopeMatchesAnIndependentRecountAndTheRemainderCountsItsRealDayOnly(): void
    {
        $this->scenario();

        // Period 2 still in preparation: provisional, never in the published cumulative.
        $p2 = $this->statistics(2);
        self::assertSame([1], $p2['cumulative']['periodNumbers']);
        self::assertSame($this->normalized($this->recount([1])), $this->sortedFamilies($this->served($p2['cumulative'])));
        self::assertSame($this->normalized($this->recount([2])), $this->sortedFamilies($this->served($p2['provisional'])));
        self::assertSame($this->normalized($this->recount([2])), $this->sortedFamilies($this->served($p2['currentPeriod'])));

        // The remainder: Sunday 2 May, a one-day "Week End" block — its holder's day and load, once.
        $remainder = $this->db()->fetchAssociative(<<<'SQL'
            SELECT tm.stable_id AS member, d.group_instance_id AS block, (SELECT COUNT(*) FROM duties d2 WHERE d2.group_instance_id = d.group_instance_id) AS days
            FROM duties d JOIN duty_assignments a ON a.duty_id = d.id AND a.current JOIN planning_team_members tm ON tm.id = a.team_member_id
            WHERE d.local_date = '2027-05-02'
        SQL);
        self::assertIsArray($remainder, 'the remainder is covered');
        self::assertSame(1, (int) $remainder['days'], 'a one-day block');
        // A whole weekend of period 2 (7-9 May): three days, one holder, on its three real weekdays.
        $whole = $this->db()->fetchAllAssociative(<<<'SQL'
            SELECT tm.stable_id AS member, d.local_date::text AS day FROM duties d
            JOIN duty_assignments a ON a.duty_id = d.id AND a.current JOIN planning_team_members tm ON tm.id = a.team_member_id
            WHERE d.local_date BETWEEN '2027-05-07' AND '2027-05-09' ORDER BY d.local_date
        SQL);
        self::assertSame(['2027-05-07', '2027-05-08', '2027-05-09'], array_column($whole, 'day'));
        self::assertCount(1, array_unique(array_column($whole, 'member')), 'a block has one holder');

        // Removing the remainder's holder from it removes exactly one Sunday, one "Week End" day and its load.
        $before = $this->served($p2['currentPeriod'])[$remainder['member']];
        $dutyStableId = (string) $this->db()->fetchOne("SELECT stable_id FROM duties WHERE local_date = '2027-05-02'");
        $this->api($this->client, 'POST', "/api/plannings/{$this->s['planningId']}/duties/{$dutyStableId}/unassign", ['expectedCurrentTeamMemberStableId' => $remainder['member']], $this->s['creator']);
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());
        $after = $this->served($this->statistics(2)['currentPeriod'])[$remainder['member']] ?? ['total' => 0, 'weekdays' => array_fill_keys(self::WEEKDAYS, 0), 'families' => [], 'weightedLoad' => 0.0];
        self::assertSame($before['total'] - 1, $after['total']);
        self::assertSame($before['weekdays']['SUN'] - 1, $after['weekdays']['SUN']);
        self::assertSame(($before['families']['Week End'] ?? 0) - 1, $after['families']['Week End'] ?? 0, 'one day of its family, never three');
        self::assertSame(round($before['weightedLoad'] - 1.0, 2), $after['weightedLoad']);
    }

    public function testOncePublishedThePeriodMovesToTheCumulativeWithTheSameNumbers(): void
    {
        $this->scenario();
        $provisional = $this->served($this->statistics(2)['provisional']);

        $this->api($this->client, 'POST', "/api/plannings/{$this->s['planningId']}/publish", ['period' => $this->periods()[1]['stableId']], $this->s['creator']);
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());

        $p2 = $this->statistics(2);
        self::assertSame([1, 2], $p2['cumulative']['periodNumbers']);
        self::assertSame([], $p2['provisional']['groups']);
        self::assertSame($this->normalized($this->recount([1, 2])), $this->sortedFamilies($this->served($p2['cumulative'])));
        $p1 = $this->served($this->statistics(1)['cumulative']);
        foreach ($this->served($p2['cumulative']) as $member => $row) {
            self::assertSame(($p1[$member]['total'] ?? 0) + ($provisional[$member]['total'] ?? 0), $row['total'], 'cumulative = period 1 + period 2, nothing counted twice');
        }
    }
}
