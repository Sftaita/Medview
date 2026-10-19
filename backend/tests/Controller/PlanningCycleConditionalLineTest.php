<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\DutyType;
use App\Entity\PlanningPeriod;
use App\Repository\PlanningGenerationRepository;
use App\Service\DutyMaterializationService;
use App\Tests\CalendarWorkflowTestHelpers;
use App\Tests\ConditionalLineTestHelpers;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * A conditional line (D162-D167) and the periods (docs/planning-cycles.md
 * §5.6, docs/decisions.md D187): a new period gives its source line and the
 * conditional line their periods in the same transaction, empty — nothing
 * materialized, nothing copied — and the line's policy stays the one in
 * force. Changing that policy on a planning with several periods waits for
 * lot L3 (refused, never applied to the wrong period).
 */
final class PlanningCycleConditionalLineTest extends WebTestCase
{
    use CalendarWorkflowTestHelpers;
    use ConditionalLineTestHelpers;

    public function testANewPeriodGivesTheSourceAndTheConditionalLineTheirPeriods(): void
    {
        $client = static::createClient();
        $s = $this->scenario($client);
        $db = static::getContainer()->get(Connection::class);
        $period1 = $this->firstPeriodEndingOnAMonday($client, $s);
        $dutiesBefore = (int) $db->fetchOne('SELECT COUNT(*) FROM duties');

        $period2 = $this->api($client, 'POST', "/api/plannings/{$s['planningId']}/periods", ['endsAt' => '2027-08-02', 'previousPeriodStableId' => $period1], $s['creator']);
        self::assertResponseStatusCodeSame(201);

        self::assertEqualsCanonicalizing([$s['primaryLineId'], $s['renfortLineId']], array_column($period2['lines'], 'lineStableId'));
        self::assertSame($dutiesBefore, (int) $db->fetchOne('SELECT COUNT(*) FROM duties'), 'nothing materialized in the new period');
        self::assertSame(1, (int) $db->fetchOne("SELECT COUNT(*) FROM planning_line_demand_policies p JOIN planning_lines l ON l.id = p.target_line_id WHERE l.stable_id = ? AND p.status = 'ACTIVE'", [$s['renfortLineId']]), 'the policy in force is untouched');

        $policy = $this->api($client, 'GET', "/api/planning-lines/{$s['renfortLineId']}/demand-policy", token: $s['creator']);
        self::assertResponseIsSuccessful();
        self::assertSame('CONDITIONAL_ON_SOURCE_ASSIGNMENT', $policy['mode'] ?? $policy['policy']['mode'] ?? null);

        $this->setPolicyExpectingRefusal($client, $s);
    }

    /**
     * Lot L3 (docs/planning-cycles.md §5.6, D189): in every period, a
     * conditional line reads its source line's period of THAT period —
     * materialization (coverage sources), and the source generation its
     * snapshot freezes.
     */
    public function testAConditionalLineReadsItsSourceInTheSamePeriod(): void
    {
        $client = static::createClient();
        $s = $this->scenario($client);
        $period1 = $this->firstPeriodEndingOnAMonday($client, $s);
        $period2 = $this->api($client, 'POST', "/api/plannings/{$s['planningId']}/periods", ['endsAt' => '2027-08-02', 'previousPeriodStableId' => $period1], $s['creator'])['stableId'];
        self::assertResponseStatusCodeSame(201);
        // The primary line's days of period 2: Tuesday 4 May and the weekend of 7-9 May.
        $primary = $this->periodIn($s, 0, $period2);
        $type = static::getContainer()->get(EntityManagerInterface::class)->getRepository(DutyType::class)->findOneBy(['team' => $primary->getTeam()]);
        foreach (['2027-05-04', '2027-05-07', '2027-05-08', '2027-05-09'] as $day) {
            static::getContainer()->get(DutyMaterializationService::class)->createStandaloneDuty($primary, $type, new \DateTimeImmutable($day), (new \DateTimeImmutable($day))->modify('+1 day'));
        }
        $db = static::getContainer()->get(Connection::class);

        $preflight = $this->api($client, 'GET', "/api/plannings/{$s['planningId']}/generation-preflight?period={$period2}", token: $s['creator']);
        self::assertResponseIsSuccessful();
        self::assertTrue($preflight['canGenerate'], json_encode($preflight['blockers'], \JSON_THROW_ON_ERROR));

        // The reinforcements of period 2 exist only where the source line has a duty in period 2, linked to it.
        self::assertSame(
            ['2027-05-04', '2027-05-07', '2027-05-08', '2027-05-09'],
            $db->fetchFirstColumn(<<<'SQL'
                SELECT d.local_date FROM duties d
                JOIN planning_periods pp ON pp.id = d.planning_period_id JOIN planning_cycles c ON c.id = pp.planning_cycle_id
                JOIN duties src ON src.id = d.coverage_source_id JOIN planning_periods sp ON sp.id = src.planning_period_id
                WHERE c.stable_id = ? AND sp.planning_cycle_id = c.id ORDER BY d.local_date
            SQL, [$period2]),
        );
        self::assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM duties d JOIN duties src ON src.id = d.coverage_source_id JOIN planning_periods dp ON dp.id = d.planning_period_id JOIN planning_periods sp ON sp.id = src.planning_period_id WHERE dp.planning_cycle_id <> sp.planning_cycle_id'), 'never a source in another period');

        // Generated: the snapshot of the reinforcement line reads the source line's generation of period 2.
        $this->api($client, 'POST', "/api/plannings/{$s['planningId']}/generations", ['period' => $period2], $s['creator']);
        self::assertResponseStatusCodeSame(202);
        $this->runQueuedPlanningJobs();
        $sourceGeneration = static::getContainer()->get(PlanningGenerationRepository::class)->findMostRecentCompletedByPlanningPeriod($this->periodIn($s, 0, $period2));
        self::assertNotNull($sourceGeneration);
        self::assertSame(
            (string) $sourceGeneration->getStableId(),
            $db->fetchOne(<<<'SQL'
                SELECT dp.source_generation_stable_id FROM planning_snapshot_demand_policies dp
                JOIN planning_snapshots sn ON sn.id = dp.snapshot_id JOIN planning_generations g ON g.id = sn.generation_id
                WHERE g.planning_period_id = ?
            SQL, [$this->periodIn($s, 1, $period2)->getId()]),
        );

        // Statistics (docs/decisions.md D201): the reinforcements of period 2 are provisional, apart, counted by their
        // real current assignments only — a reinforcement nobody needs and nobody holds is no load.
        $statistics = $this->api($client, 'GET', "/api/plannings/{$s['planningId']}/statistics?period={$period2}", token: $s['creator']);
        self::assertResponseIsSuccessful();
        self::assertNotContains(2, $statistics['cumulative']['periodNumbers']);
        $renfort = array_column($statistics['provisional']['groups'], null, 'groupLabel')['Renfort'];
        self::assertSame([2], $renfort['periodNumbers']);
        $held = (int) $db->fetchOne('SELECT COUNT(*) FROM duty_assignments a JOIN duties d ON d.id = a.duty_id WHERE a.current AND d.planning_period_id IN (SELECT pp.id FROM planning_periods pp WHERE pp.planning_line_id = (SELECT planning_line_id FROM planning_periods WHERE id = ?))', [$this->periodIn($s, 1, $period2)->getId()]);
        self::assertGreaterThan(0, $held, 'fixture: reinforcements are held');
        self::assertSame($held, array_sum(array_column($renfort['members'], 'total')), 'the reinforcement line: its real assignments, every period summed in the provisional scope');
    }

    public function testAConditionalLineWhoseSourceTakesNoPartInThePeriodIsBlocked(): void
    {
        $client = static::createClient();
        $s = $this->scenario($client);
        $period1 = $this->firstPeriodEndingOnAMonday($client, $s);
        $period2 = $this->api($client, 'POST', "/api/plannings/{$s['planningId']}/periods", ['endsAt' => '2027-08-02', 'previousPeriodStableId' => $period1], $s['creator'])['stableId'];
        self::assertResponseStatusCodeSame(201);
        $db = static::getContainer()->get(Connection::class);
        // The source line has no period in period 2 (it has nothing there yet: no duty, no history).
        $db->executeStatement('DELETE FROM planning_periods WHERE id = ?', [$this->periodIn($s, 0, $period2)->getId()]);

        $preflight = $this->api($client, 'GET', "/api/plannings/{$s['planningId']}/generation-preflight?period={$period2}", token: $s['creator']);
        self::assertResponseIsSuccessful();
        self::assertContains(['code' => 'CONDITIONAL_SOURCE_NOT_IN_PERIOD', 'lineStableId' => $s['renfortLineId'], 'lineName' => 'Renfort'], $preflight['blockers']);
        self::assertSame([$s['renfortLineId']], array_values(array_unique(array_column($preflight['blockers'], 'lineStableId'))), 'only the reinforcement line is concerned (nothing to place either)');
        self::assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM duties WHERE planning_period_id = ?', [$this->periodIn($s, 1, $period2)->getId()]), 'never materialized as independent duties');

        $this->api($client, 'POST', "/api/plannings/{$s['planningId']}/generations", ['period' => $period2], $s['creator']);
        self::assertResponseStatusCodeSame(409);
    }

    /**
     * docs/decisions.md D192: the end of a locked period 1 (Saturday 1 May,
     * exclusive) cuts the reinforcement weekend of 30 April → 2 May, never
     * materialized. Once confirmed, its days of period 2 are a remainder block
     * — conditional, each linked to the source line's duty of THAT day in
     * period 2 (§5.6), never to anything of period 1.
     */
    public function testARemainderOfAConditionalBlockReadsItsSourceInTheNewPeriod(): void
    {
        $client = static::createClient();
        $s = $this->scenario($client);
        $db = static::getContainer()->get(Connection::class);
        self::assertSame(0, (int) $db->fetchOne("SELECT COUNT(*) FROM duties WHERE local_date = '2027-04-30'"));
        // Period 1 locked: the primary line published.
        $db->executeStatement("UPDATE planning_periods SET status = 'PUBLISHED' WHERE planning_line_id = (SELECT id FROM planning_lines WHERE stable_id = ?)", [$s['primaryLineId']]);
        $period1 = $this->api($client, 'GET', "/api/plannings/{$s['planningId']}/periods", token: $s['creator'])['periods'][0]['stableId'];

        $created = $this->api($client, 'POST', "/api/plannings/{$s['planningId']}/periods", ['endsAt' => '2027-08-02', 'previousPeriodStableId' => $period1, 'acceptHistoricalBlockCuts' => true], $s['creator']);
        self::assertResponseStatusCodeSame(201, json_encode($created, \JSON_THROW_ON_ERROR));
        self::assertSame([['Renfort', ['2027-05-01', '2027-05-02']]], array_map(static fn (array $c): array => [$c['lineName'], $c['remainderDays']], $created['historicalBlockCuts']));
        $primary = $this->periodIn($s, 0, $created['stableId']);
        $type = static::getContainer()->get(EntityManagerInterface::class)->getRepository(DutyType::class)->findOneBy(['team' => $primary->getTeam()]);
        foreach (['2027-05-01', '2027-05-02'] as $day) {
            static::getContainer()->get(DutyMaterializationService::class)->createStandaloneDuty($primary, $type, new \DateTimeImmutable($day), (new \DateTimeImmutable($day))->modify('+1 day'));
        }

        $preflight = $this->api($client, 'GET', "/api/plannings/{$s['planningId']}/generation-preflight?period={$created['stableId']}", token: $s['creator']);
        self::assertResponseIsSuccessful();
        self::assertContains(['code' => 'HISTORICAL_BLOCK_REMAINDER', 'lineStableId' => $s['renfortLineId'], 'lineName' => 'Renfort'], $preflight['warnings']);
        self::assertSame(
            [['2027-05-01', 'CONDITIONAL', '2027-05-01'], ['2027-05-02', 'CONDITIONAL', '2027-05-02']],
            array_map(static fn (array $r): array => [$r['day'], $r['demand_type'], $r['source_day']], $db->fetchAllAssociative(<<<'SQL'
                SELECT d.local_date::text AS day, d.demand_type, src.local_date::text AS source_day
                FROM duties d JOIN planning_periods pp ON pp.id = d.planning_period_id JOIN planning_cycles c ON c.id = pp.planning_cycle_id
                JOIN duties src ON src.id = d.coverage_source_id JOIN planning_periods sp ON sp.id = src.planning_period_id
                WHERE c.stable_id = ? AND sp.planning_cycle_id = c.id AND d.group_instance_id IS NOT NULL AND d.local_date <= '2027-05-02'
                ORDER BY d.local_date
            SQL, [$created['stableId']])),
        );
        self::assertSame(0, (int) $db->fetchOne("SELECT COUNT(*) FROM duties WHERE local_date = '2027-04-30'"), 'nothing in period 1');
    }

    /**
     * The renfort line has a Friday → Sunday block: period 1 (ending Saturday
     * 1 May) must end on a Monday before a period can follow it (lot L3, no
     * boundary inside a block).
     *
     * @param array<string, mixed> $s
     */
    private function firstPeriodEndingOnAMonday(KernelBrowser $client, array $s): string
    {
        $period1 = $this->api($client, 'GET', "/api/plannings/{$s['planningId']}/periods", token: $s['creator'])['periods'][0]['stableId'];
        $this->api($client, 'PATCH', "/api/plannings/{$s['planningId']}/periods/{$period1}", ['endsAt' => '2027-05-03', 'expectedEndsAt' => '2027-05-01'], $s['creator']);
        self::assertResponseIsSuccessful();

        return $period1;
    }

    /**
     * @param array<string, mixed> $s
     */
    private function periodIn(array $s, int $lineIndex, string $periodStableId): PlanningPeriod
    {
        static::getContainer()->get(EntityManagerInterface::class)->clear();
        $line = $this->line($s['planningId'], $lineIndex);
        foreach ($line->getPeriods() as $period) {
            if ((string) $period->getCycle()?->getStableId() === $periodStableId) {
                return $period;
            }
        }

        self::fail("Line {$lineIndex} has no period {$periodStableId}.");
    }

    /**
     * @param array<string, mixed> $s
     */
    private function setPolicyExpectingRefusal($client, array $s): void
    {
        $this->api($client, 'PUT', "/api/planning-lines/{$s['renfortLineId']}/demand-policy", [
            'schemaVersion' => 1,
            'mode' => 'INDEPENDENT',
            'triggers' => [],
        ], $s['creator']);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('multiple_periods_not_supported', json_decode((string) $client->getResponse()->getContent(), true)['error'] ?? null);
    }
}
