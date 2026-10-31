<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Repository\PlanningLineRepository;
use App\Repository\PlanningRepository;
use App\Service\DutyMaterializationService;
use App\Service\PlanningCycleAudit;
use App\Service\PlanningRuleSetService;
use App\Tests\CalendarWorkflowTestHelpers;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * docs/decisions.md D207 (lot 1 of the manual preparation of a period): the
 * dates of the periods, chosen on a calendar and checked by the server.
 *
 * Fixture (pilotScenario): planning 2027-01-01 (a Friday) → 2027-05-01,
 * period 1, primary line "Seniors" (admin/alice/bob), its first collection
 * open and untouched (nobody answered yet).
 */
final class PlanningPeriodDatesTest extends WebTestCase
{
    use CalendarWorkflowTestHelpers;

    /** Friday → Sunday block, single weekdays (the structure of Trauma Delta). */
    private const STRUCTURE = [
        'blocks' => [['name' => 'Ven · Sam · Dim', 'days' => ['VEN', 'SAM', 'DIM'], 'family' => 'Week End']],
        'solo' => ['LUN', 'MAR', 'MER', 'JEU'],
        'soloFamily' => 'Jours de semaine',
        'excluded' => [],
    ];

    private KernelBrowser $client;

    /** @var array<string, mixed> */
    private array $s;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->s = $this->pilotScenario($this->client);
    }

    // --- helpers ---------------------------------------------------------------------------------

    private function db(): Connection
    {
        return static::getContainer()->get(Connection::class);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function periods(): array
    {
        return $this->api($this->client, 'GET', "/api/plannings/{$this->s['planningId']}/periods", token: $this->s['creator'])['periods'];
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function patch(string $period, array $body, ?string $token = null): array
    {
        return $this->api($this->client, 'PATCH', "/api/plannings/{$this->s['planningId']}/periods/{$period}", $body, $token ?? $this->s['creator']);
    }

    /**
     * @return array<string, mixed>
     */
    private function options(string $period, string $edge, string $from, string $to, ?string $token = null): array
    {
        return $this->api($this->client, 'GET', "/api/plannings/{$this->s['planningId']}/periods/date-options?period={$period}&edge={$edge}&from={$from}&to={$to}", token: $token ?? $this->s['creator']);
    }

    private function createPeriod(string $endsAt, bool $acceptHistorical = false): string
    {
        $periods = $this->periods();
        $body = ['endsAt' => $endsAt, 'previousPeriodStableId' => $periods[\count($periods) - 1]['stableId']];
        if ($acceptHistorical) {
            $body['acceptHistoricalBlockCuts'] = true;
        }
        $created = $this->api($this->client, 'POST', "/api/plannings/{$this->s['planningId']}/periods", $body, $this->s['creator']);
        self::assertResponseStatusCodeSame(201, (string) $this->client->getResponse()->getContent());

        return $created['stableId'];
    }

    private function assertError(int $status, string $code): void
    {
        self::assertResponseStatusCodeSame($status, (string) $this->client->getResponse()->getContent());
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame($code, $body['error'] ?? null, (string) $this->client->getResponse()->getContent());
    }

    /**
     * The dates of everything that follows a period: cycles, line periods, team registries, planning, collections.
     *
     * @return array<string, mixed>
     */
    private function dates(): array
    {
        $db = $this->db();

        return [
            'cycles' => $db->fetchAllAssociative('SELECT number, starts_at::text AS s, ends_at::text AS e FROM planning_cycles ORDER BY number'),
            'linePeriods' => $db->fetchAllAssociative('SELECT c.number, pp.starts_at::text AS s, pp.ends_at::text AS e FROM planning_periods pp JOIN planning_cycles c ON c.id = pp.planning_cycle_id WHERE pp.planning_line_id IS NOT NULL ORDER BY c.number, pp.id'),
            'fairness' => $db->fetchAllAssociative('SELECT starts_at::text AS s, ends_at::text AS e FROM fairness_periods ORDER BY id'),
            'planning' => $db->fetchAssociative('SELECT starts_at::text AS s, ends_at::text AS e FROM plannings'),
            'collections' => $db->fetchAllAssociative('SELECT c.number, ac.starts_at::text AS s, ac.ends_at::text AS e FROM availability_collections ac JOIN planning_cycles c ON c.id = ac.planning_cycle_id ORDER BY ac.id'),
        ];
    }

    private function assertAuditClean(): void
    {
        $audit = static::getContainer()->get(PlanningCycleAudit::class);
        self::assertSame([], $audit->violations($audit->report()));
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, list<string>> date → codes of its reasons
     */
    private static function reasonsByDay(array $options): array
    {
        $byDay = [];
        foreach ($options['days'] as $day) {
            $byDay[$day['date']] = array_column($day['reasons'], 'code');
            self::assertSame([] === $day['reasons'], $day['allowed']);
        }

        return $byDay;
    }

    private function useWeekendBlocks(): void
    {
        $line = $this->api($this->client, 'GET', "/api/plannings/{$this->s['planningId']}", token: $this->s['creator'])['lines'][0]['stableId'];
        $this->api($this->client, 'PUT', "/api/planning-lines/{$line}/week-structure", self::STRUCTURE, $this->s['creator']);
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());
    }

    // --- 1. the first period, blank -----------------------------------------------------------------

    public function testTheStartAndEndOfABlankFirstPeriodMoveAndItsUntouchedCollectionFollows(): void
    {
        $period1 = $this->periods()[0]['stableId'];
        self::assertSame(3, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM availability_collection_responses'), 'admin, alice, bob');

        // Later start, earlier end: the untouched collection takes the new dates.
        $moved = $this->patch($period1, ['startsAt' => '2027-02-01', 'expectedStartsAt' => '2027-01-01', 'endsAt' => '2027-04-01', 'expectedEndsAt' => '2027-05-01']);
        self::assertResponseIsSuccessful();
        self::assertSame(['2027-02-01', '2027-04-01', '2027-03-31'], [$moved['startsAt'], $moved['endsAt'], $moved['lastDay']]);
        self::assertSame([
            'cycles' => [['number' => 1, 's' => '2027-02-01', 'e' => '2027-04-01']],
            'linePeriods' => [['number' => 1, 's' => '2027-02-01', 'e' => '2027-04-01']],
            'fairness' => [['s' => '2027-02-01', 'e' => '2027-04-01']],
            'planning' => ['s' => '2027-02-01', 'e' => '2027-04-01'],
            'collections' => [['number' => 1, 's' => '2027-02-01', 'e' => '2027-04-01']],
        ], $this->dates());
        self::assertSame(3, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM availability_collection_responses WHERE acknowledged_at IS NULL'), 'still one per person expected');
        self::assertSame(0, $moved['collections']['uncoveredDays']);

        // The whole period earlier than it ever was: its start moves first, never an intermediate state too short.
        $earlier = $this->patch($period1, ['startsAt' => '2026-11-01', 'expectedStartsAt' => '2027-02-01', 'endsAt' => '2026-12-01', 'expectedEndsAt' => '2027-04-01']);
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());
        self::assertSame(['2026-11-01', '2026-12-01'], [$earlier['startsAt'], $earlier['endsAt']]);
        self::assertSame(['s' => '2026-11-01', 'e' => '2026-12-01'], $this->dates()['planning']);
        self::assertSame([['number' => 1, 's' => '2026-11-01', 'e' => '2026-12-01']], $this->dates()['collections']);
        $this->assertAuditClean();
    }

    public function testAnAnsweredCollectionBoundsTheFirstPeriodAndAStaleViewIsRefused(): void
    {
        $period1 = $this->periods()[0]['stableId'];
        // Touched: a calendar change inside its window (D214: no confirmation any more).
        $this->api($this->client, 'POST', '/api/me/calendar', ['type' => 'UNAVAILABLE', 'startsAt' => '2027-02-10T00:00:00+01:00', 'endsAt' => '2027-02-12T00:00:00+01:00'], $this->s['alice']);
        self::assertResponseIsSuccessful();

        $this->patch($period1, ['startsAt' => '2027-01-15', 'expectedStartsAt' => '2027-01-01']);
        $this->assertError(409, 'collection_answered');
        $options = self::reasonsByDay($this->options($period1, 'start', '2026-12-30', '2027-01-03'));
        self::assertSame([], $options['2026-12-30'], 'earlier: the answered collection stays inside');
        self::assertSame(['collection_answered'], $options['2027-01-02']);

        $grown = $this->patch($period1, ['startsAt' => '2026-12-01', 'expectedStartsAt' => '2027-01-01']);
        self::assertResponseIsSuccessful();
        self::assertSame('2026-12-01', $grown['startsAt']);
        self::assertSame([['number' => 1, 's' => '2027-01-01', 'e' => '2027-05-01']], $this->dates()['collections'], 'an answered collection keeps its window');

        $this->patch($period1, ['startsAt' => '2026-11-01', 'expectedStartsAt' => '2027-01-01']);
        $this->assertError(409, 'period_changed');
        $this->patch($period1, ['endsAt' => '2027-06-01']);
        $this->assertError(422, 'invalid_date');
        $this->patch($period1, []);
        $this->assertError(422, 'invalid_request');
    }

    public function testTheStartOfTheFirstPeriodNeverMovesOnceItHasDutiesNorEverForAFollowingPeriod(): void
    {
        $this->prepareLine($this->s['planningId'], [['2027-01-05', '2027-01-06']]);
        $period1 = $this->periods()[0]['stableId'];

        $this->patch($period1, ['startsAt' => '2026-12-01', 'expectedStartsAt' => '2027-01-01']);
        $this->assertError(409, 'period_not_blank');
        $options = $this->options($period1, 'start', '2026-12-01', '2026-12-05');
        self::assertFalse($options['editable']);
        self::assertSame(['period_not_blank'], array_column($options['blockers'], 'code'));

        // Its end, the last one, still grows as before (lot L2).
        $this->patch($period1, ['endsAt' => '2027-06-01', 'expectedEndsAt' => '2027-05-01']);
        self::assertResponseIsSuccessful();

        $period2 = $this->createPeriod('2027-07-01');
        $this->patch($period2, ['startsAt' => '2027-06-15', 'expectedStartsAt' => '2027-06-01']);
        $this->assertError(409, 'period_start_follows_previous');
        self::assertSame(['period_start_follows_previous'], array_column($this->options($period2, 'start', '2027-06-01', '2027-06-02')['blockers'], 'code'));
    }

    // --- 2. the boundary between two periods -------------------------------------------------------

    public function testTheBoundaryBetweenTwoBlankPeriodsMovesWithoutGapNorOverlap(): void
    {
        $period1 = $this->periods()[0]['stableId'];
        $period2 = $this->createPeriod('2027-08-01');
        $this->addSecondaryLine($this->client, $this->s, ['carol@example.com']);

        // Earlier: period 1 shrinks first, then period 2 starts earlier; period 1's untouched collection follows.
        $this->patch($period1, ['endsAt' => '2027-04-01', 'expectedEndsAt' => '2027-05-01']);
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());
        $dates = $this->dates();
        self::assertSame([['number' => 1, 's' => '2027-01-01', 'e' => '2027-04-01'], ['number' => 2, 's' => '2027-04-01', 'e' => '2027-08-01']], $dates['cycles']);
        self::assertSame(['s' => '2027-01-01', 'e' => '2027-08-01'], $dates['planning'], 'the planning keeps its envelope');
        self::assertSame([['number' => 1, 's' => '2027-01-01', 'e' => '2027-04-01']], $dates['collections']);
        foreach ($dates['linePeriods'] as $period) {
            self::assertSame(1 === $period['number'] ? ['2027-01-01', '2027-04-01'] : ['2027-04-01', '2027-08-01'], [$period['s'], $period['e']], 'both lines, both periods');
        }
        self::assertCount(4, $dates['linePeriods']);
        $this->assertAuditClean();

        // Later, once someone touched it: period 2 starts later first, then period 1 grows; the collection stays.
        $this->api($this->client, 'POST', '/api/me/calendar', ['type' => 'UNAVAILABLE', 'startsAt' => '2027-02-10T00:00:00+01:00', 'endsAt' => '2027-02-12T00:00:00+01:00'], $this->s['alice']);
        self::assertResponseIsSuccessful();
        $this->patch($period1, ['endsAt' => '2027-06-01', 'expectedEndsAt' => '2027-04-01']);
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());
        self::assertSame([['number' => 1, 's' => '2027-01-01', 'e' => '2027-06-01'], ['number' => 2, 's' => '2027-06-01', 'e' => '2027-08-01']], $this->dates()['cycles']);
        self::assertSame([['number' => 1, 's' => '2027-01-01', 'e' => '2027-04-01']], $this->dates()['collections']);
        $this->patch($period1, ['endsAt' => '2027-03-01', 'expectedEndsAt' => '2027-06-01']);
        $this->assertError(409, 'collection_answered');
        $this->assertAuditClean();

        // Period 2 keeps 7 days at least.
        $this->patch($period1, ['endsAt' => '2027-07-28', 'expectedEndsAt' => '2027-06-01']);
        $this->assertError(422, 'next_period_too_short');
        self::assertSame(['next_period_too_short'], self::reasonsByDay($this->options($period1, 'end', '2027-07-27', '2027-07-27'))['2027-07-27']);
    }

    public function testTheBoundaryNeverMovesOnceEitherPeriodHasHistory(): void
    {
        $period1 = $this->periods()[0]['stableId'];
        $period2 = $this->createPeriod('2027-08-01');
        $container = static::getContainer();
        $planning = $container->get(PlanningRepository::class)->findOneByStableId($this->s['planningId']);
        $seniors = $container->get(PlanningLineRepository::class)->findByPlanning($planning)[0];
        $this->createDuty($container->get(DutyMaterializationService::class), $seniors->periodIn($planning->getCycles()[1]), $this->createDutyType($container->get(EntityManagerInterface::class), $seniors->getPlanningTeam(), 'P2'), '2027-06-10', '2027-06-11');

        $this->patch($period1, ['endsAt' => '2027-05-08', 'expectedEndsAt' => '2027-05-01']);
        $this->assertError(409, 'next_period_not_blank');
        self::assertSame(['next_period_not_blank'], array_column($this->options($period1, 'end', '2027-05-01', '2027-05-10')['blockers'], 'code'));
        // The last period's own end still moves.
        $this->patch($period2, ['endsAt' => '2027-09-01', 'expectedEndsAt' => '2027-08-01']);
        self::assertResponseIsSuccessful();
    }

    public function testTheBoundaryNeverMovesOnceThePeriodBeforeHasHistory(): void
    {
        $this->prepareLine($this->s['planningId'], [['2027-01-05', '2027-01-06']]);
        $period1 = $this->periods()[0]['stableId'];
        $this->createPeriod('2027-08-01');

        $this->patch($period1, ['endsAt' => '2027-04-01', 'expectedEndsAt' => '2027-05-01']);
        $this->assertError(409, 'period_not_blank');
        self::assertSame(['period_not_blank'], array_column($this->options($period1, 'end', '2027-04-01', '2027-04-02')['blockers'], 'code'));
        self::assertSame([['number' => 1, 's' => '2027-01-01', 'e' => '2027-05-01'], ['number' => 2, 's' => '2027-05-01', 'e' => '2027-08-01']], $this->dates()['cycles']);
    }

    // --- 3. blocks ---------------------------------------------------------------------------------

    public function testNoDateEverCutsABlockAndTheOptionsSayWhichOne(): void
    {
        $this->useWeekendBlocks();
        $period1 = $this->periods()[0]['stableId'];

        // 2027-04-30 is a Friday: a last day on Friday or Saturday cuts the weekend block.
        $byDay = self::reasonsByDay($this->options($period1, 'end', '2027-04-29', '2027-05-03'));
        self::assertSame(['2027-04-29' => [], '2027-04-30' => ['period_boundary_splits_block'], '2027-05-01' => ['period_boundary_splits_block'], '2027-05-02' => [], '2027-05-03' => []], $byDay);
        $friday = $this->options($period1, 'end', '2027-04-30', '2027-04-30')['days'][0]['reasons'][0]['cuts'][0];
        self::assertSame(['Seniors', 'Ven · Sam · Dim', '2027-04-30', '2027-05-02'], [$friday['lineName'], $friday['blockName'], $friday['firstDay'], $friday['lastDay']]);

        $this->patch($period1, ['endsAt' => '2027-05-02', 'expectedEndsAt' => '2027-05-01']);
        $this->assertError(422, 'period_boundary_splits_block');
        $this->patch($period1, ['endsAt' => '2027-05-03', 'expectedEndsAt' => '2027-05-01']);
        self::assertResponseIsSuccessful();

        // The first period's start (Saturday 2 or Sunday 3 January) and a new period's end follow the same rule.
        self::assertSame(['period_boundary_splits_block'], self::reasonsByDay($this->options($period1, 'start', '2027-01-02', '2027-01-02'))['2027-01-02']);
        $next = self::reasonsByDay($this->options('next', 'end', '2027-06-04', '2027-06-07'));
        self::assertSame(['2027-06-04' => ['period_boundary_splits_block'], '2027-06-05' => ['period_boundary_splits_block'], '2027-06-06' => [], '2027-06-07' => []], $next);
        $this->api($this->client, 'POST', "/api/plannings/{$this->s['planningId']}/periods", ['endsAt' => '2027-06-05', 'previousPeriodStableId' => $period1], $this->s['creator']);
        $this->assertError(422, 'period_boundary_splits_block');
    }

    /**
     * The production case (D192): period 1 published up to Saturday 30 January, period 2 created from Sunday
     * 31 January with the remainder of the cut weekend — its start never moves, its end does while it is blank.
     */
    public function testTheSecondPeriodOfTraumaDeltaKeepsItsStartAndChoosesItsEnd(): void
    {
        $period1 = $this->periods()[0]['stableId'];
        $this->db()->executeStatement("UPDATE availability_collections SET ends_at = '2027-01-31' WHERE stable_id = ?", [$this->s['collectionId']]);
        $this->patch($period1, ['endsAt' => '2027-01-31', 'expectedEndsAt' => '2027-05-01']);
        self::assertResponseIsSuccessful();
        $this->useWeekendBlocks();
        $container = static::getContainer();
        $planning = $container->get(PlanningRepository::class)->findOneByStableId($this->s['planningId']);
        $this->activateRuleSet($container->get(PlanningRuleSetService::class), $container->get(PlanningLineRepository::class)->findByPlanning($planning)[0]->getPlanningTeam());
        $this->generate($this->client, $this->s);
        $this->api($this->client, 'POST', "/api/plannings/{$this->s['planningId']}/publish", [], $this->s['creator']);
        self::assertResponseIsSuccessful();

        $next = $this->options('next', 'end', '2027-04-29', '2027-05-03');
        self::assertSame('2027-01-31', $next['fixedStart']);
        self::assertTrue($next['editable']);
        self::assertSame([['Ven · Sam · Dim', ['2027-01-29', '2027-01-30'], ['2027-01-31']]], array_map(static fn (array $cut): array => [$cut['blockName'], $cut['earlierDays'], $cut['remainderDays']], $next['historicalStartCuts']));
        self::assertSame(['period_boundary_splits_block'], self::reasonsByDay($next)['2027-04-30']);
        $period2 = $this->createPeriod('2027-05-03', acceptHistorical: true);
        $period1Before = $this->db()->fetchOne('SELECT md5(string_agg(ROW(pp.*)::text, \'|\' ORDER BY pp.id)) FROM planning_periods pp JOIN planning_cycles c ON c.id = pp.planning_cycle_id WHERE c.number = 1');

        // Its start: always the day after period 1, which is published.
        $this->patch($period2, ['startsAt' => '2027-02-01', 'expectedStartsAt' => '2027-01-31']);
        $this->assertError(409, 'period_start_follows_previous');
        $this->patch($period1, ['endsAt' => '2027-02-07', 'expectedEndsAt' => '2027-01-31']);
        $this->assertError(409, 'period_locked');
        $locked = $this->options($period1, 'end', '2027-02-07', '2027-02-08');
        self::assertFalse($locked['editable']);
        self::assertSame(['2027-02-07' => ['period_locked'], '2027-02-08' => ['period_locked']], self::reasonsByDay($locked), 'no day is offered while the period is locked');

        // Its end, while it is blank: on any day but a Friday or a Saturday (the weekend block).
        $byDay = self::reasonsByDay($this->options($period2, 'end', '2027-02-01', '2027-02-07'));
        self::assertSame(['period_too_short'], $byDay['2027-02-01'], 'a week at least');
        self::assertSame(['period_too_short', 'period_boundary_splits_block'], $byDay['2027-02-05'], 'every reason, not only the first');
        self::assertSame(['period_boundary_splits_block'], $byDay['2027-02-06']);
        self::assertSame([], $byDay['2027-02-07']);
        $shortened = $this->patch($period2, ['endsAt' => '2027-04-05', 'expectedEndsAt' => '2027-05-03']);
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());
        self::assertSame(['2027-01-31', '2027-04-04'], [$shortened['startsAt'], $shortened['lastDay']]);
        self::assertSame($period1Before, $this->db()->fetchOne('SELECT md5(string_agg(ROW(pp.*)::text, \'|\' ORDER BY pp.id)) FROM planning_periods pp JOIN planning_cycles c ON c.id = pp.planning_cycle_id WHERE c.number = 1'), 'period 1 untouched');
        $this->assertAuditClean();
    }

    // --- 4. the options endpoint -------------------------------------------------------------------

    public function testOnlyTheCreatorReadsTheOptionsWithinABoundedRange(): void
    {
        $period1 = $this->periods()[0]['stableId'];
        $this->options($period1, 'end', '2027-04-01', '2027-04-02', $this->s['alice']);
        self::assertResponseStatusCodeSame(403);
        $this->options($period1, 'end', '2027-04-01', '2028-06-01');
        $this->assertError(422, 'invalid_range');
        $this->options($period1, 'end', '2027-04-02', '2027-04-01');
        $this->assertError(422, 'invalid_range');
        $this->options('next', 'start', '2027-04-01', '2027-04-02');
        $this->assertError(422, 'invalid_request');
        $this->options($period1, 'middle', '2027-04-01', '2027-04-02');
        $this->assertError(422, 'invalid_request');
        $this->options('01a15e52-4559-7752-b1f4-76f2c5d5658f', 'end', '2027-04-01', '2027-04-02');
        $this->assertError(404, 'period_not_found');

        $options = $this->options($period1, 'end', '2027-04-01', '2027-04-03');
        self::assertSame(['stableId' => $period1, 'number' => 1], $options['period']);
        self::assertSame(['2027-01-01', '2027-04-30', true, []], [$options['fixedStart'], $options['current'], $options['editable'], $options['blockers']]);
        self::assertSame(['2027-04-01', '2027-04-02', '2027-04-03'], array_column($options['days'], 'date'));
        self::assertSame(['period_too_short'], self::reasonsByDay($this->options($period1, 'end', '2027-01-06', '2027-01-06'))['2027-01-06']);
        self::assertSame([], self::reasonsByDay($this->options($period1, 'end', '2027-01-07', '2027-01-07'))['2027-01-07'], '7 days: 1 to 7 January');
    }

    public function testNoDateMovesWhileACalculationIsRunning(): void
    {
        $period1 = $this->periods()[0]['stableId'];
        $this->prepareLine($this->s['planningId'], [['2027-01-05', '2027-01-06']]);
        $this->api($this->client, 'POST', "/api/plannings/{$this->s['planningId']}/generations", [], $this->s['creator']);
        self::assertResponseStatusCodeSame(202);

        self::assertSame(['job_in_progress'], array_column($this->options($period1, 'end', '2027-04-01', '2027-04-01')['blockers'], 'code'));
        $this->patch($period1, ['endsAt' => '2027-06-01', 'expectedEndsAt' => '2027-05-01']);
        $this->assertError(409, 'job_in_progress');
    }

    // --- 5. the intact collection (D207, completed by D208) ------------------------------------------

    public function testACollectionPeopleWereWrittenToIsNeverResizedAndTheRefusalSaysWhy(): void
    {
        $period1 = $this->periods()[0]['stableId'];
        $sent = $this->api($this->client, 'POST', "/api/plannings/{$this->s['planningId']}/reminders/participants", [], $this->s['creator']);
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());
        self::assertGreaterThan(0, $sent['sentCount']);
        $before = $this->dates();

        $this->patch($period1, ['endsAt' => '2027-04-01', 'expectedEndsAt' => '2027-05-01']);
        $this->assertError(409, 'collection_announced');
        $this->patch($period1, ['startsAt' => '2027-01-15', 'expectedStartsAt' => '2027-01-01']);
        $this->assertError(409, 'collection_announced');
        self::assertSame(['collection_announced'], self::reasonsByDay($this->options($period1, 'end', '2027-03-31', '2027-03-31'))['2027-03-31']);
        self::assertSame(['collection_announced'], self::reasonsByDay($this->options($period1, 'start', '2027-01-15', '2027-01-15'))['2027-01-15']);
        self::assertSame($before, $this->dates(), 'nothing moved');

        // Growing never resizes it either: the window people were asked about stays, the added days are uncovered (§5.2).
        $grown = $this->patch($period1, ['endsAt' => '2027-06-01', 'expectedEndsAt' => '2027-05-01']);
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());
        self::assertSame([['number' => 1, 's' => '2027-01-01', 'e' => '2027-05-01']], $this->dates()['collections']);
        self::assertSame(31, $grown['collections']['uncoveredDays']);
    }

    public function testAReminderSentBeforePeriodsExistedCountsOnlyOnceTheCollectionWasOpen(): void
    {
        $period1 = $this->periods()[0]['stableId'];
        $legacyReminder = static function (Connection $db, string $sentAt): void {
            // A reminder from before periods existed: no planning_cycle_id (never backfilled, append-only).
            $db->executeStatement(<<<'SQL'
                INSERT INTO planning_availability_reminders (stable_id, channel, bulk, pending_collection_count, sent_at, planning_id, recipient_id, sent_by_id, planning_cycle_id)
                SELECT gen_random_uuid(), 'EMAIL', TRUE, 1, CAST(:sentAt AS timestamp), p.id, u.id, u.id, NULL FROM plannings p, users u ORDER BY u.id LIMIT 1
                SQL, ['sentAt' => $sentAt]);
        };
        $openedAt = (string) $this->db()->fetchOne('SELECT opened_at::text FROM availability_collections');

        $legacyReminder($this->db(), (new \DateTimeImmutable($openedAt))->modify('-1 day')->format('Y-m-d H:i:s'));
        $this->patch($period1, ['endsAt' => '2027-04-01', 'expectedEndsAt' => '2027-05-01']);
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());
        self::assertSame([['number' => 1, 's' => '2027-01-01', 'e' => '2027-04-01']], $this->dates()['collections'], 'a reminder older than the collection is about something else');

        $legacyReminder($this->db(), (new \DateTimeImmutable($openedAt))->modify('+1 hour')->format('Y-m-d H:i:s'));
        $this->patch($period1, ['endsAt' => '2027-03-01', 'expectedEndsAt' => '2027-04-01']);
        $this->assertError(409, 'collection_announced');
    }

    public function testAnyRecordedChangeOrAClosedCollectionKeepsItsWindow(): void
    {
        $period1 = $this->periods()[0]['stableId'];

        // An absence deleted in the window is a change too (it existed before the collection: no change recorded yet).
        $absence = $this->declareRange($this->client, $this->s['alice'], '2027-02-01', '2027-02-03');
        $this->db()->executeStatement('UPDATE availability_collection_responses SET last_availability_change_at = NULL');
        $this->api($this->client, 'DELETE', "/api/me/calendar/{$absence['stableId']}", token: $this->s['alice']);
        self::assertResponseIsSuccessful();
        $this->patch($period1, ['endsAt' => '2027-04-01', 'expectedEndsAt' => '2027-05-01']);
        $this->assertError(409, 'collection_answered');

        // Nobody answered, but the collection was closed: frozen history, never resized.
        $this->db()->executeStatement('UPDATE availability_collection_responses SET last_availability_change_at = NULL');
        $this->patch($period1, ['endsAt' => '2027-04-01', 'expectedEndsAt' => '2027-05-01']);
        self::assertResponseIsSuccessful('intact again: it follows');
        $this->api($this->client, 'POST', "/api/availability-collections/{$this->s['collectionId']}/close", [], $this->s['creator']);
        self::assertResponseIsSuccessful();
        $this->patch($period1, ['endsAt' => '2027-03-01', 'expectedEndsAt' => '2027-04-01']);
        $this->assertError(409, 'collection_beyond_end');
        self::assertSame([['number' => 1, 's' => '2027-01-01', 'e' => '2027-04-01']], $this->dates()['collections']);
    }

    // --- 6. what the period view announces -----------------------------------------------------------

    public function testThePeriodViewAnnouncesExactlyWhatTheRulesAllow(): void
    {
        $period1 = $this->periods()[0]['stableId'];
        $period2 = $this->createPeriod('2027-08-01');
        $announced = function () use ($period1, $period2): array {
            $flags = [];
            foreach ([$period1, $period2] as $i => $period) {
                $view = $this->periods()[$i];
                self::assertSame($view['canEditStart'], $this->options($period, 'start', '2027-01-01', '2027-01-01')['editable'], 'start of period '.($i + 1));
                self::assertSame($view['canEditEnd'], $this->options($period, 'end', '2027-07-01', '2027-07-01')['editable'], 'end of period '.($i + 1));
                $flags[] = [$view['canEditStart'], $view['canEditEnd']];
            }

            return $flags;
        };

        // Both blank: the boundary moves (the end of period 1, no longer the last one); only the first start moves.
        self::assertSame([[true, true], [false, true]], $announced());

        // A duty in period 2: the boundary is fixed, the last end still moves.
        $container = static::getContainer();
        $planning = $container->get(PlanningRepository::class)->findOneByStableId($this->s['planningId']);
        $seniors = $container->get(PlanningLineRepository::class)->findByPlanning($planning)[0];
        $this->createDuty($container->get(DutyMaterializationService::class), $seniors->periodIn($planning->getCycles()[1]), $this->createDutyType($container->get(EntityManagerInterface::class), $seniors->getPlanningTeam(), 'P2'), '2027-06-10', '2027-06-11');
        self::assertSame([[true, false], [false, true]], $announced());
    }

    // --- 7. one move, all or nothing -----------------------------------------------------------------

    public function testABoundaryMoveThatFailsHalfwayLeavesNeitherGapNorOverlap(): void
    {
        $period1 = $this->periods()[0]['stableId'];
        $this->createPeriod('2027-08-01');
        $this->addSecondaryLine($this->client, $this->s, ['carol@example.com']);
        $before = $this->dates();
        $db = $this->db();
        $db->executeStatement(<<<'SQL'
            CREATE FUNCTION test_fail_second_write() RETURNS trigger AS $$
            BEGIN
              RAISE EXCEPTION 'injected failure of the second write';
            END;
            $$ LANGUAGE plpgsql
            SQL);

        // Later: period 2 starts later first (written), then the end of period 1 fails.
        $db->executeStatement('CREATE TRIGGER test_fail_second_write BEFORE UPDATE ON planning_cycles FOR EACH ROW WHEN (OLD.number = 1 AND NEW.ends_at IS DISTINCT FROM OLD.ends_at) EXECUTE FUNCTION test_fail_second_write()');
        $this->patch($period1, ['endsAt' => '2027-06-01', 'expectedEndsAt' => '2027-05-01']);
        self::assertResponseStatusCodeSame(500);
        self::assertSame($before, $this->dates(), 'rolled back: period 2 did not keep its new start');
        $db->executeStatement('DROP TRIGGER test_fail_second_write ON planning_cycles');

        // Earlier: period 1 ends earlier first (written), then the start of period 2 fails.
        $db->executeStatement('CREATE TRIGGER test_fail_second_write BEFORE UPDATE ON planning_cycles FOR EACH ROW WHEN (OLD.number = 2 AND NEW.starts_at IS DISTINCT FROM OLD.starts_at) EXECUTE FUNCTION test_fail_second_write()');
        $this->patch($period1, ['endsAt' => '2027-04-01', 'expectedEndsAt' => '2027-05-01']);
        self::assertResponseStatusCodeSame(500);
        self::assertSame($before, $this->dates(), 'rolled back: period 1 did not keep its new end');
        $db->executeStatement('DROP TRIGGER test_fail_second_write ON planning_cycles');
        $this->assertAuditClean();

        // Nothing left half done: the same move now succeeds.
        $this->patch($period1, ['endsAt' => '2027-04-01', 'expectedEndsAt' => '2027-05-01']);
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());
        self::assertSame([['number' => 1, 's' => '2027-01-01', 'e' => '2027-04-01'], ['number' => 2, 's' => '2027-04-01', 'e' => '2027-08-01']], $this->dates()['cycles']);
    }
}
