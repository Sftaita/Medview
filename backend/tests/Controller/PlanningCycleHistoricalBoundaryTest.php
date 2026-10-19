<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Service\PlanningRuleSetService;
use App\Tests\CalendarWorkflowTestHelpers;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The historical block boundary (docs/decisions.md D192): the end of a
 * locked period 1 — fixed before lot L3, when it was the only period — cuts a
 * Friday → Sunday block. Its earlier days were never materialized there; the
 * next period is created only once the manager confirms it, and the days of
 * that occurrence in the new period become a remainder block of the same
 * pattern. Never anything in period 1, never for an ordinary boundary.
 *
 * Fixture (pilotScenario): planning 2027-01-01 → 2027-05-01 (exclusive: the
 * last day of period 1 is Friday 30 April), primary line "Seniors" with
 * admin/alice/bob. Real OR-Tools generations.
 */
final class PlanningCycleHistoricalBoundaryTest extends WebTestCase
{
    use CalendarWorkflowTestHelpers;

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
     * @return array<string, mixed>
     */
    private function createPeriod(string $endsAt, bool $accept = false): array
    {
        $periods = $this->periods();

        return $this->api($this->client, 'POST', "/api/plannings/{$this->s['planningId']}/periods", [
            'endsAt' => $endsAt,
            'previousPeriodStableId' => $periods[\count($periods) - 1]['stableId'],
        ] + ($accept ? ['acceptHistoricalBlockCuts' => true] : []), $this->s['creator']);
    }

    private function setStructure(array $structure = self::STRUCTURE): void
    {
        $line = $this->api($this->client, 'GET', "/api/plannings/{$this->s['planningId']}", token: $this->s['creator'])['lines'][0]['stableId'];
        $this->api($this->client, 'PUT', "/api/planning-lines/{$line}/week-structure", $structure, $this->s['creator']);
    }

    /**
     * Period 1 as it is in production: generated with its weekly structure (its last weekend cut by its end, never
     * materialized), then published — its end can no longer move.
     */
    private function publishedPeriodOne(?string $endsAt = null, ?string $extraDuty = null): void
    {
        if (null !== $endsAt) {
            $period = $this->periods()[0];
            $this->api($this->client, 'PATCH', "/api/plannings/{$this->s['planningId']}/periods/{$period['stableId']}", ['endsAt' => $endsAt, 'expectedEndsAt' => $period['endsAt']], $this->s['creator']);
            self::assertResponseIsSuccessful();
        }
        $this->setStructure();
        self::assertResponseIsSuccessful();
        $planning = static::getContainer()->get(\App\Repository\PlanningRepository::class)->findOneByStableId($this->s['planningId']);
        $line = static::getContainer()->get(\App\Repository\PlanningLineRepository::class)->findByPlanning($planning)[0];
        $this->activateRuleSet(static::getContainer()->get(PlanningRuleSetService::class), $line->getPlanningTeam());
        if (null !== $extraDuty) {
            // A day of the cut occurrence that DID get a duty in period 1 (another way than the block).
            $type = $this->createDutyType(static::getContainer()->get(\Doctrine\ORM\EntityManagerInterface::class), $line->getPlanningTeam(), 'EXTRA');
            $this->createDuty(static::getContainer()->get(\App\Service\DutyMaterializationService::class), $line->requireSingleCyclePeriod(), $type, $extraDuty, (new \DateTimeImmutable($extraDuty))->modify('+1 day')->format('Y-m-d'));
        }
        $job = $this->generateNow($this->client, $this->s['planningId'], $this->s['creator']);
        self::assertSame('SUCCEEDED', $job['status'], json_encode($job, \JSON_THROW_ON_ERROR));
        $this->api($this->client, 'POST', "/api/plannings/{$this->s['planningId']}/publish", [], $this->s['creator']);
        self::assertResponseIsSuccessful();
    }

    private function fingerprintOfPeriodOne(): string
    {
        return (string) $this->db()->fetchOne(<<<'SQL'
            SELECT md5(string_agg(x, '|' ORDER BY x)) FROM (
              SELECT 'y' || ROW(cy.*)::text AS x FROM planning_cycles cy WHERE cy.number = 1
              UNION ALL SELECT 'p' || ROW(pp.*)::text FROM planning_periods pp JOIN planning_cycles cy ON cy.id = pp.planning_cycle_id WHERE cy.number = 1
              UNION ALL SELECT 'd' || ROW(d.*)::text FROM duties d JOIN planning_periods pp ON pp.id = d.planning_period_id JOIN planning_cycles cy ON cy.id = pp.planning_cycle_id WHERE cy.number = 1
              UNION ALL SELECT 'g' || ROW(gi.*)::text FROM duty_group_instances gi JOIN planning_periods pp ON pp.id = gi.planning_period_id JOIN planning_cycles cy ON cy.id = pp.planning_cycle_id WHERE cy.number = 1
              UNION ALL SELECT 'a' || ROW(a.*)::text FROM duty_assignments a JOIN duties d ON d.id = a.duty_id JOIN planning_periods pp ON pp.id = d.planning_period_id JOIN planning_cycles cy ON cy.id = pp.planning_cycle_id WHERE cy.number = 1
              UNION ALL SELECT 'u' || ROW(u.*)::text FROM planning_publications u
              UNION ALL SELECT 'e' || ROW(e.*)::text FROM planning_publication_entries e
            ) s
        SQL);
    }

    /**
     * @return list<array<string, mixed>> the duties of the remainder block(s) in period 2: day, pattern, family, holder
     */
    private function remainderDuties(): array
    {
        return $this->db()->fetchAllAssociative(<<<'SQL'
            SELECT d.local_date::text AS day, p.name AS pattern, f.name AS family, d.group_instance_id AS block, d.demand_type
            FROM duties d
            JOIN planning_periods pp ON pp.id = d.planning_period_id JOIN planning_cycles cy ON cy.id = pp.planning_cycle_id
            JOIN duty_group_instances gi ON gi.id = d.group_instance_id JOIN duty_patterns p ON p.id = gi.pattern_id
            LEFT JOIN allocation_families f ON f.id = p.family_id
            WHERE cy.number = 2 AND (SELECT COUNT(*) FROM duties d2 WHERE d2.group_instance_id = gi.id) < (SELECT COUNT(*) FROM duty_pattern_components c WHERE c.pattern_id = p.id)
            ORDER BY d.local_date
        SQL);
    }

    private function preflightOfPeriodTwo(): array
    {
        $preflight = $this->api($this->client, 'GET', "/api/plannings/{$this->s['planningId']}/generation-preflight?period={$this->periods()[1]['stableId']}", token: $this->s['creator']);
        self::assertResponseIsSuccessful();

        return $preflight;
    }

    // --- the historical case ----------------------------------------------------------------------

    /**
     * The production case (D190/D191): period 1 ends (exclusive) on a Sunday —
     * Friday and Saturday in period 1, never materialized; Sunday, a one-day
     * remainder, in period 2.
     */
    public function testTheHistoricalCaseLeavesAOneDayRemainderInTheNextPeriod(): void
    {
        $this->publishedPeriodOne(endsAt: '2027-05-02');
        self::assertSame(0, (int) $this->db()->fetchOne("SELECT COUNT(*) FROM duties WHERE local_date BETWEEN '2027-04-30' AND '2027-05-01'"), 'the cut weekend was never materialized in period 1');
        $periodOne = $this->fingerprintOfPeriodOne();

        // Without the confirmation: refused, the anomaly described.
        $refused = $this->createPeriod('2027-08-02');
        self::assertResponseStatusCodeSame(409);
        self::assertSame('historical_block_cut_confirmation_required', $refused['error']);
        self::assertSame(['Seniors', 'Ven · Sam · Dim', '2027-05-02', ['2027-04-30', '2027-05-01'], ['2027-05-02']], [
            $refused['details'][0]['lineName'], $refused['details'][0]['blockName'], $refused['details'][0]['boundary'],
            $refused['details'][0]['earlierDays'], $refused['details'][0]['remainderDays'],
        ]);
        self::assertCount(1, $this->periods(), 'nothing created');

        $created = $this->createPeriod('2027-08-02', accept: true);
        self::assertResponseStatusCodeSame(201, json_encode($created, \JSON_THROW_ON_ERROR));
        self::assertSame(['2027-05-02'], $created['historicalBlockCuts'][0]['remainderDays']);
        self::assertSame($periodOne, $this->fingerprintOfPeriodOne(), 'period 1 strictly unchanged');

        $preflight = $this->preflightOfPeriodTwo();
        self::assertTrue($preflight['canGenerate'], json_encode($preflight['blockers'], \JSON_THROW_ON_ERROR));
        self::assertContains('HISTORICAL_BLOCK_REMAINDER', array_column($preflight['warnings'], 'code'));
        $remainder = $this->remainderDuties();
        self::assertSame([['2027-05-02', 'Ven · Sam · Dim', 'Week End', 'REQUIRED']], array_map(static fn (array $d): array => [$d['day'], $d['pattern'], $d['family'], $d['demand_type']], $remainder));
        self::assertSame($periodOne, $this->fingerprintOfPeriodOne(), 'still nothing in period 1');

        // Generated (real OR-Tools): the remainder is covered like any duty — period 2 is fully covered.
        $job = $this->generateNow($this->client, $this->s['planningId'], $this->s['creator'], ['period' => $this->periods()[1]['stableId']]);
        self::assertSame('SUCCEEDED', $job['status']);
        self::assertSame('COMPLETE', $job['outcome']['coverage']);
        self::assertNotNull($this->db()->fetchOne("SELECT a.id FROM duty_assignments a JOIN duties d ON d.id = a.duty_id WHERE d.local_date = '2027-05-02' AND a.current"));
        self::assertSame($periodOne, $this->fingerprintOfPeriodOne());
    }

    /** Period 1 ends (exclusive) on a Saturday: a two-day remainder, one block, one holder. */
    public function testASeveralDayRemainderStaysOneBlockWithOneHolder(): void
    {
        $this->publishedPeriodOne();
        $periodOne = $this->fingerprintOfPeriodOne();
        $this->createPeriod('2027-08-02', accept: true);
        self::assertResponseStatusCodeSame(201);

        $this->preflightOfPeriodTwo();
        $remainder = $this->remainderDuties();
        self::assertSame(['2027-05-01', '2027-05-02'], array_column($remainder, 'day'));
        self::assertSame($remainder[0]['block'], $remainder[1]['block'], 'one block');

        $job = $this->generateNow($this->client, $this->s['planningId'], $this->s['creator'], ['period' => $this->periods()[1]['stableId']]);
        self::assertSame('SUCCEEDED', $job['status']);
        $holders = $this->db()->fetchFirstColumn("SELECT DISTINCT a.team_member_id FROM duty_assignments a JOIN duties d ON d.id = a.duty_id WHERE d.local_date IN ('2027-05-01', '2027-05-02') AND a.current");
        self::assertCount(1, $holders, 'atomic: both days to the same person');
        self::assertSame($periodOne, $this->fingerprintOfPeriodOne());
    }

    // --- never an ordinary boundary ---------------------------------------------------------------

    public function testAnOrdinaryBoundaryIsAlwaysRefusedEvenWithTheConfirmation(): void
    {
        // Period 1 not locked: its end can still move — no exception.
        $this->setStructure();
        self::assertResponseIsSuccessful();
        $refused = $this->createPeriod('2027-08-02', accept: true);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('period_boundary_splits_block', $refused['error']);

        // Locked period 1, but a new end that cuts a block: the end is always still to be chosen.
        $this->publishedPeriodOne();
        $refused = $this->createPeriod('2027-08-01', accept: true);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('2027-08-01', $refused['details'][0]['boundary']);
        self::assertCount(1, $this->periods());
    }

    public function testAnEarlierDayAlreadyHoldingADutyIsNoHistoricalCut(): void
    {
        // Friday 30 April got a duty in period 1 (another duty type, not the block): the occurrence was not simply
        // left out — no exception, whatever the confirmation.
        $this->publishedPeriodOne(extraDuty: '2027-04-30');
        $refused = $this->createPeriod('2027-08-02', accept: true);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('period_boundary_splits_block', $refused['error']);
    }

    public function testTheWeeklyStructureStaysEditableButNeverCutsTheNextBoundary(): void
    {
        $this->publishedPeriodOne();
        $this->createPeriod('2027-08-02', accept: true);
        self::assertResponseStatusCodeSame(201);

        // The same structure again: the historical cut is tolerated, as when the period was created.
        $this->setStructure();
        self::assertResponseIsSuccessful();
        // Monday + Sunday of one week (non-contiguous): it spans the Saturday 1 May boundary, and its Monday already holds a
        // duty in period 1 — an ordinary cut, refused.
        $this->setStructure([
            'blocks' => [['name' => 'Dim · Lun', 'days' => ['DIM', 'LUN'], 'family' => '']],
            'solo' => ['MAR', 'MER', 'JEU', 'VEN', 'SAM'],
            'soloFamily' => '',
            'excluded' => [],
        ]);
        self::assertResponseStatusCodeSame(422);
        $this->setStructure([
            'blocks' => [['name' => 'Sam · Dim', 'days' => ['SAM', 'DIM'], 'family' => '']],
            'solo' => ['LUN', 'MAR', 'MER', 'JEU', 'VEN'],
            'soloFamily' => '',
            'excluded' => [],
        ]);
        self::assertResponseIsSuccessful();
        $period2 = $this->periods()[1];
        $this->api($this->client, 'PATCH', "/api/plannings/{$this->s['planningId']}/periods/{$period2['stableId']}", ['endsAt' => '2027-08-08', 'expectedEndsAt' => $period2['endsAt']], $this->s['creator']);
        self::assertResponseStatusCodeSame(422, 'a new end on a Sunday cuts Saturday → Sunday');

        // The end of the last period is the next boundary: a structure never cuts it either.
        $this->api($this->client, 'PATCH', "/api/plannings/{$this->s['planningId']}/periods/{$period2['stableId']}", ['endsAt' => '2027-08-03', 'expectedEndsAt' => $period2['endsAt']], $this->s['creator']);
        self::assertResponseIsSuccessful();
        $this->setStructure([
            'blocks' => [['name' => 'Lun · Mar', 'days' => ['LUN', 'MAR'], 'family' => '']],
            'solo' => ['MER', 'JEU', 'VEN', 'SAM', 'DIM'],
            'soloFamily' => '',
            'excluded' => [],
        ]);
        self::assertResponseStatusCodeSame(422, 'Monday 2 → Tuesday 3 August across the end (exclusive) of period 2');
    }
}
