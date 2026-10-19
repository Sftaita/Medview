<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\DutyType;
use App\Entity\PlanningPeriod;
use App\Service\DutyMaterializationService;
use App\Service\PlanningRuleSetService;
use App\Tests\CalendarWorkflowTestHelpers;
use App\Tests\PlanningCycleTestHelpers;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/**
 * Statistics on a planning with several periods, lot L5 (docs/decisions.md
 * D201): the selected period, the published cumulative of periods 1 to the
 * selected one, and the provisional line periods apart — each line period
 * on exactly one side, by its own status; counts unchanged (current
 * assignments, duty by duty, weekday, family by day, real workload).
 *
 * Fixture (pilotScenario, Europe/Brussels): period 1 2027-01-01 → 05-01,
 * period 2 → 08-01, period 3 → 11-01. Holders forced by the manager:
 *
 *   period 1  T1 Tue 27 Apr bob        N1 Fri 30 Apr alice
 *   period 2  S2 Sun 2 May  alice      C2 Tue 4 May  admin
 *   period 3  P3 Tue 3 Aug  alice
 */
final class PlanningCycleStatisticsTest extends WebTestCase
{
    use CalendarWorkflowTestHelpers;
    use ClockSensitiveTrait;
    use PlanningCycleTestHelpers;

    private KernelBrowser $client;

    /** @var array<string, mixed> */
    private array $s;

    /** @var array<string, string> */
    private array $d = [];

    protected function setUp(): void
    {
        self::mockTime('2026-12-10 09:00:00 Europe/Brussels');
        $this->client = static::createClient();
        $this->s = $this->pilotScenario($this->client);
    }

    /**
     * @param list<int>             $published
     * @param callable(): void|null $beforeHolders run once every period is generated, before the holders are forced
     * @param array<string, string> $p2Holders     overrides of period 2's holders
     */
    private function scenario(array $published, ?callable $beforeHolders = null, array $p2Holders = []): void
    {
        $this->createPeriod('2027-08-01');
        $this->createPeriod('2027-11-01');
        $this->addDuties(1, [['2027-04-27 08:00', '2027-04-27 20:00'], ['2027-04-30 20:00', '2027-05-01 08:00']]);
        $this->addDuties(2, [['2027-05-02 10:00', '2027-05-02 18:00'], ['2027-05-04 08:00', '2027-05-04 20:00']]);
        $this->addDuties(3, [['2027-08-03 08:00', '2027-08-03 20:00']]);
        foreach ([1, 2, 3] as $number) {
            $this->generatePeriod($number);
        }
        $this->d = [
            'T1' => $this->dutyIn(1, '2027-04-27'),
            'N1' => $this->dutyIn(1, '2027-04-30'),
            'S2' => $this->dutyIn(2, '2027-05-02'),
            'C2' => $this->dutyIn(2, '2027-05-04'),
            'P3' => $this->dutyIn(3, '2027-08-03'),
        ];
        if (null !== $beforeHolders) {
            $beforeHolders();
        }
        $this->forceHolders([
            $this->d['T1'] => 'bob@example.com',
            $this->d['N1'] => 'alice@example.com',
            $this->d['S2'] => $p2Holders['S2'] ?? 'alice@example.com',
            $this->d['C2'] => $p2Holders['C2'] ?? 'admin@example.com',
            $this->d['P3'] => 'alice@example.com',
        ]);
        foreach ($published as $number) {
            $this->publishPeriod($number);
        }
    }

    /** @return array<string, mixed> */
    private function statistics(int $period, string $who = 'creator'): array
    {
        $statistics = $this->api($this->client, 'GET', "/api/plannings/{$this->s['planningId']}/statistics?period={$this->periodId($period)}", token: $this->s[$who]);
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());

        return $statistics;
    }

    /**
     * @param array<string, mixed> $scope
     *
     * @return array<string, array<string, mixed>> email → row, in the group of line $lineName (absent group: [])
     */
    private function rows(array $scope, string $lineName = 'Seniors', int $lineIndex = 0): array
    {
        $emailOf = [];
        foreach (['admin', 'alice', 'bob', 'dave'] as $who) {
            try {
                $emailOf[$this->memberStableIdIn($this->s['planningId'], "{$who}@example.com", $lineIndex)] = "{$who}@example.com";
            } catch (\Throwable) {
            }
        }
        foreach ($scope['groups'] as $group) {
            if ($group['groupLabel'] === $lineName) {
                $rows = [];
                foreach ($group['members'] as $row) {
                    $rows[$emailOf[$row['teamMemberStableId']] ?? $row['teamMemberStableId']] = $row;
                }
                ksort($rows);

                return $rows;
            }
        }

        return [];
    }

    /**
     * @param array<string, array<string, mixed>> $rows
     *
     * @return array<string, int> email → total
     */
    private function totals(array $rows): array
    {
        return array_map(static fn (array $row): int => $row['total'], $rows);
    }

    // --- 2, 3, 13: three periods, two published, one in preparation ------------------------------------------

    public function testThreePeriodsTwoPublishedAndOneInPreparationAreNeverMixed(): void
    {
        $this->scenario([1, 2]);

        $p3 = $this->statistics(3);
        self::assertSame(['number' => 3, 'startsAt' => '2027-08-01', 'endsAt' => '2027-11-01'], ['number' => $p3['period']['number'], 'startsAt' => $p3['period']['startsAt'], 'endsAt' => $p3['period']['endsAt']]);
        self::assertSame([['Seniors', 'UNPUBLISHED']], array_map(static fn (array $l): array => [$l['lineName'], $l['publication']], $p3['period']['lines']));
        // The selected period: period 3 only, provisional.
        self::assertSame(['alice@example.com' => 1], $this->totals($this->rows($p3['currentPeriod'])));
        self::assertTrue($p3['currentPeriod']['groups'][0]['provisional']);
        self::assertSame([3], $p3['currentPeriod']['groups'][0]['periodNumbers']);
        // The published cumulative: periods 1 and 2 only — never period 3.
        self::assertSame(['admin@example.com' => 1, 'alice@example.com' => 2, 'bob@example.com' => 1], $this->totals($this->rows($p3['cumulative'])));
        self::assertSame([1, 2], $p3['cumulative']['periodNumbers']);
        self::assertSame(3, $p3['cumulative']['throughPeriodNumber']);
        self::assertSame(['2027-01-01', '2027-11-01'], [$p3['cumulative']['startsAt'], $p3['cumulative']['endsAt']]);
        self::assertSame(['FRI' => 1, 'SUN' => 1], array_filter($this->rows($p3['cumulative'])['alice@example.com']['countsByWeekday']));
        // In preparation, apart: period 3.
        self::assertSame(['alice@example.com' => 1], $this->totals($this->rows($p3['provisional'])));
        self::assertSame([3], $p3['provisional']['periodNumbers']);
        self::assertSame(['TUE' => 1], array_filter($this->rows($p3['provisional'])['alice@example.com']['countsByWeekday']));

        // Through period 2: the same published cumulative, nothing provisional (period 3 comes after).
        $p2 = $this->statistics(2);
        self::assertSame($p3['cumulative']['groups'], $p2['cumulative']['groups']);
        self::assertSame([], $p2['provisional']['groups']);
        self::assertSame(['admin@example.com' => 1, 'alice@example.com' => 1], $this->totals($this->rows($p2['currentPeriod'])));
        self::assertSame('PUBLISHED', $p2['currentPeriod']['groups'][0]['publication']);

        // Through period 1: period 1 alone.
        $p1 = $this->statistics(1);
        self::assertSame(['alice@example.com' => 1, 'bob@example.com' => 1], $this->totals($this->rows($p1['cumulative'])));
        self::assertSame([1], $p1['cumulative']['periodNumbers']);
        self::assertSame($this->rows($p1['currentPeriod']), $this->rows($p1['cumulative']), 'period 1 alone, published: the same numbers');

        // Publishing period 3 moves it, once, to the cumulative.
        $this->publishPeriod(3);
        $after = $this->statistics(3);
        self::assertSame(['admin@example.com' => 1, 'alice@example.com' => 3, 'bob@example.com' => 1], $this->totals($this->rows($after['cumulative'])));
        self::assertSame([1, 2, 3], $after['cumulative']['periodNumbers']);
        self::assertSame([], $after['provisional']['groups']);
    }

    // --- 6: reassignment after publication ----------------------------------------------------------------------

    public function testAReassignmentAfterPublicationIsCountedInTheCumulativeAtOnce(): void
    {
        $this->scenario([1, 2]);
        $this->reassignTo($this->client, $this->s, $this->d['C2'], 'bob@example.com');
        self::assertResponseIsSuccessful();

        $statistics = $this->statistics(2);
        self::assertSame(['alice@example.com' => 2, 'bob@example.com' => 2], $this->totals($this->rows($statistics['cumulative'])), 'the current calendar, as the statistics always read it');
        self::assertSame(['alice@example.com' => 1, 'bob@example.com' => 1], $this->totals($this->rows($statistics['currentPeriod'])));
    }

    // --- 12: weighted load ----------------------------------------------------------------------------------------

    public function testTheWeightedLoadIsTheRealWorkloadOfEachDutySummedOverThePeriods(): void
    {
        $this->scenario([1, 2]);
        $this->db()->executeStatement("UPDATE duty_types SET workload_value = '1.50' WHERE code = 'ONCALL'");

        $statistics = $this->statistics(3);
        self::assertSame(3.0, (float) $this->rows($statistics['cumulative'])['alice@example.com']['weightedLoad'], '2 duties × 1.5');
        self::assertSame(1.5, (float) $this->rows($statistics['provisional'])['alice@example.com']['weightedLoad']);
        self::assertSame(['Oncall' => 2], $this->rows($statistics['cumulative'])['alice@example.com']['countsByDutyType']);
    }

    // --- 8: memberships ended or started between periods ---------------------------------------------------------

    public function testAMembershipEndedOrStartedBetweenPeriodsKeepsItsOwnRowAndOnlyItsOwnDuties(): void
    {
        $this->userToken($this->client, 'dave@example.com');
        $this->addMemberAs($this->client, $this->s['creator'], $this->s['planningId'], $this->s['teamId'], 'dave@example.com', 'MEMBER');
        $this->db()->executeStatement("UPDATE planning_team_members SET membership_start = '2027-05-01' WHERE user_id = (SELECT id FROM users WHERE email = 'dave@example.com')");
        $this->scenario([1, 2], function (): void {
            $this->api($this->client, 'POST', "/api/plannings/{$this->s['planningId']}/teams/{$this->s['teamId']}/members/{$this->memberIdOf($this->client, $this->s, 'bob@example.com')}/end", ['membershipEnd' => '2027-05-01'], $this->s['creator']);
            self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());
        }, ['C2' => 'dave@example.com']);

        $cumulative = $this->rows($this->statistics(2)['cumulative']);
        self::assertSame(['alice@example.com' => 2, 'bob@example.com' => 1, 'dave@example.com' => 1], $this->totals($cumulative));
        self::assertSame(['TUE' => 1], array_filter($cumulative['bob@example.com']['countsByWeekday']), 'bob: his period-1 duty, kept after he left');
        self::assertSame(['TUE' => 1], array_filter($cumulative['dave@example.com']['countsByWeekday']), 'dave: period 2 only');
        self::assertSame(['alice@example.com' => 1, 'bob@example.com' => 1], $this->totals($this->rows($this->statistics(1)['cumulative'])), 'through period 1: dave not there yet');
    }

    // --- 14: permissions -------------------------------------------------------------------------------------------

    public function testAPlainMemberReadsOnlyWhatIsPublishedAndThePeriodMustBeNamed(): void
    {
        $this->scenario([1, 2]);

        $member = $this->statistics(3, 'alice');
        self::assertSame([], $member['currentPeriod']['groups'], 'period 3 is not published');
        self::assertNull($member['provisional']);
        self::assertSame('UNPUBLISHED', $member['period']['lines'][0]['publication']);
        self::assertSame($this->statistics(3)['cumulative'], $member['cumulative'], 'the published cumulative: the same numbers as a manager');
        self::assertSame($this->statistics(2)['currentPeriod'], $this->statistics(2, 'alice')['currentPeriod'], 'a published period: the same');

        $this->api($this->client, 'GET', "/api/plannings/{$this->s['planningId']}/statistics", token: $this->s['creator']);
        $this->assertError(422, 'period_required');
        $this->api($this->client, 'GET', "/api/plannings/{$this->s['planningId']}/statistics?period=00000000-0000-0000-0000-000000000000", token: $this->s['creator']);
        self::assertResponseStatusCodeSame(404);
        $this->api($this->client, 'GET', "/api/plannings/{$this->s['planningId']}/statistics?period={$this->periodId(2)}", token: $this->s['outsider']);
        self::assertResponseStatusCodeSame(403);
    }

    // --- 4, 5, 9: a partially published period, a person on two lines, a line without demand ---------------------

    public function testAPartiallyPublishedPeriodCountsEachLinePeriodOnceOnItsOwnSide(): void
    {
        $this->createPeriod('2027-08-01');
        $this->api($this->client, 'POST', "/api/plannings/{$this->s['planningId']}/lines", ['name' => 'Juniors', 'fromPeriodStableId' => $this->periodId(2)], $this->s['creator']);
        self::assertResponseStatusCodeSame(201, (string) $this->client->getResponse()->getContent());
        $detail = $this->api($this->client, 'GET', "/api/plannings/{$this->s['planningId']}", token: $this->s['creator']);
        [$seniors, $juniors] = [$detail['lines'][0]['stableId'], $detail['lines'][1]];
        foreach (['alice@example.com', 'bob@example.com'] as $email) {
            $this->addMemberAs($this->client, $this->s['creator'], $this->s['planningId'], $juniors['team']['stableId'], $email, 'MEMBER');
        }
        $this->createPeriod('2027-11-01');

        $this->addDuties(1, [['2027-04-27 08:00', '2027-04-27 20:00'], ['2027-04-30 20:00', '2027-05-01 08:00']]);
        $this->addDuties(2, [['2027-05-02 10:00', '2027-05-02 18:00'], ['2027-05-04 08:00', '2027-05-04 20:00']]);
        $this->addJuniorsDuties(2, [['2027-05-05 08:00', '2027-05-05 20:00']]);
        $this->generatePeriod(1);
        $this->generatePeriod(2);
        $this->forceHolders([$this->dutyIn(1, '2027-04-27') => 'bob@example.com', $this->dutyIn(1, '2027-04-30') => 'alice@example.com', $this->dutyIn(2, '2027-05-02') => 'alice@example.com', $this->dutyIn(2, '2027-05-04') => 'admin@example.com']);
        $this->forceHolders([$this->juniorsDutyIn(2, '2027-05-05') => 'alice@example.com'], 1);
        $this->publishPeriod(1);
        // Period 2: "Seniors" published, "Juniors" not (docs/decisions.md D196).
        $this->api($this->client, 'POST', "/api/plannings/{$this->s['planningId']}/publish", ['period' => $this->periodId(2), 'lines' => [$seniors]], $this->s['creator']);
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());

        $p2 = $this->statistics(2);
        self::assertSame([['Seniors', 'PUBLISHED'], ['Juniors', 'UNPUBLISHED']], array_map(static fn (array $l): array => [$l['lineName'], $l['publication']], $p2['period']['lines']));
        self::assertSame(['PUBLISHED', 'UNPUBLISHED'], array_column($p2['currentPeriod']['groups'], 'publication'));
        self::assertSame(['Seniors'], array_column($p2['cumulative']['groups'], 'groupLabel'), 'one published line does not publish the other');
        self::assertSame(['Juniors'], array_column($p2['provisional']['groups'], 'groupLabel'));
        // Alice on both lines: each group counts its own line's duties, never summed into one.
        self::assertSame(2, $this->rows($p2['cumulative'])['alice@example.com']['total']);
        self::assertSame(1, $this->rows($p2['provisional'], 'Juniors', 1)['alice@example.com']['total']);
        self::assertSame(1, $this->rows($p2['currentPeriod'])['alice@example.com']['total']);
        self::assertSame(1, $this->rows($p2['currentPeriod'], 'Juniors', 1)['alice@example.com']['total']);
        // A member: the published line only.
        self::assertSame(['Seniors'], array_column($this->statistics(2, 'alice')['currentPeriod']['groups'], 'groupLabel'));

        // Period 3: "Juniors" has a period but no duty asked — no generation, no load, no group of its own.
        $p3 = $this->statistics(3);
        self::assertSame([['Seniors', 'NOT_GENERATED'], ['Juniors', 'NOT_GENERATED']], array_map(static fn (array $l): array => [$l['lineName'], $l['publication']], $p3['period']['lines']));
        self::assertSame([], $p3['currentPeriod']['groups']);
        self::assertSame([2], array_column($p3['provisional']['groups'], 'periodNumbers')[0], 'only period 2 of "Juniors"');
    }

    /** @param list<array{0: string, 1: string}> $duties */
    private function addJuniorsDuties(int $number, array $duties): void
    {
        $period = $this->em()->find(PlanningPeriod::class, $this->juniorsPeriodId($number));
        $team = $period->getTeam();
        $this->activateRuleSet(static::getContainer()->get(PlanningRuleSetService::class), $team);
        $dutyType = $this->em()->getRepository(DutyType::class)->findOneBy(['team' => $team, 'code' => 'ONCALL']) ?? $this->createDutyType($this->em(), $team);
        $period = $this->em()->find(PlanningPeriod::class, $period->getId());
        foreach ($duties as [$from, $to]) {
            static::getContainer()->get(DutyMaterializationService::class)->createStandaloneDuty($period, $dutyType, new \DateTimeImmutable($from), new \DateTimeImmutable($to));
        }
    }

    private function juniorsPeriodId(int $number): int
    {
        return (int) $this->db()->fetchOne(<<<'SQL'
            SELECT pp.id FROM planning_periods pp
            JOIN planning_cycles c ON c.id = pp.planning_cycle_id JOIN planning_lines l ON l.id = pp.planning_line_id
            JOIN plannings p ON p.id = c.planning_id
            WHERE p.stable_id = ? AND c.number = ? AND l.name = 'Juniors'
        SQL, [$this->s['planningId'], $number]);
    }

    private function juniorsDutyIn(int $number, string $localDate): string
    {
        return (string) $this->db()->fetchOne('SELECT stable_id FROM duties WHERE planning_period_id = ? AND local_date = ?', [$this->juniorsPeriodId($number), $localDate]);
    }
}
