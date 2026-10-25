<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Repository\PlanningLineRepository;
use App\Repository\PlanningRepository;
use App\Service\PlanningCycleAudit;
use App\Service\PlanningRuleSetService;
use App\Tests\CalendarWorkflowTestHelpers;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\Exception\AbortMigration;
use DoctrineMigrations\Version20261025090000;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * docs/decisions.md D211 — the rectification of a locked period's end, on
 * the Trauma Delta case: period 1 published up to Saturday 30 January 2027
 * (its last duty on Thursday 28, the weekend of 29-31 not fitting), period 2
 * created from Sunday 31 with the remainder of that weekend (D192). The
 * boundary is brought back to Friday 29: the weekend goes whole into period
 * 2, and nothing of period 1's calendar changes.
 *
 * Fixture (pilotScenario): planning 2027-01-01 → 2027-05-01, primary line
 * "Seniors" (admin/alice/bob), brought to the Trauma Delta state by the
 * public API.
 */
final class PeriodEndRectificationTest extends WebTestCase
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
     * Period 1 published up to Saturday 30 January (end 31, exclusive), period 2 from Sunday 31 to 2 May with the
     * remainder of the weekend — exactly the production state, which has no availability collection at all
     * (unless $withCollection: the fixture's own, kept up to 31 January).
     *
     * @return array{0: string, 1: string} the two periods' stable ids
     */
    private function traumaDelta(bool $withCollection = false): array
    {
        $period1 = $this->periods()[0]['stableId'];
        $this->db()->executeStatement("UPDATE availability_collections SET ends_at = '2027-01-31' WHERE stable_id = ?", [$this->s['collectionId']]);
        $this->api($this->client, 'PATCH', "/api/plannings/{$this->s['planningId']}/periods/{$period1}", ['endsAt' => '2027-01-31', 'expectedEndsAt' => '2027-05-01'], $this->s['creator']);
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());
        $line = $this->api($this->client, 'GET', "/api/plannings/{$this->s['planningId']}", token: $this->s['creator'])['lines'][0]['stableId'];
        $this->api($this->client, 'PUT', "/api/planning-lines/{$line}/week-structure", self::STRUCTURE, $this->s['creator']);
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());
        $container = static::getContainer();
        $planning = $container->get(PlanningRepository::class)->findOneByStableId($this->s['planningId']);
        $this->activateRuleSet($container->get(PlanningRuleSetService::class), $container->get(PlanningLineRepository::class)->findByPlanning($planning)[0]->getPlanningTeam());
        $this->generate($this->client, $this->s);
        $this->api($this->client, 'POST', "/api/plannings/{$this->s['planningId']}/publish", [], $this->s['creator']);
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());
        $created = $this->api($this->client, 'POST', "/api/plannings/{$this->s['planningId']}/periods", ['endsAt' => '2027-05-03', 'previousPeriodStableId' => $period1, 'acceptHistoricalBlockCuts' => true], $this->s['creator']);
        self::assertResponseStatusCodeSame(201, (string) $this->client->getResponse()->getContent());
        if (!$withCollection) {
            $this->db()->executeStatement('DELETE FROM availability_collection_responses');
            $this->db()->executeStatement('DELETE FROM availability_collections');
        }

        return [$period1, $created['stableId']];
    }

    /**
     * @return array<string, mixed>
     */
    private function options(string $period, ?string $token = null): array
    {
        return $this->api($this->client, 'GET', "/api/plannings/{$this->s['planningId']}/periods/{$period}/end-rectification", token: $token ?? $this->s['creator']);
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function rectify(string $period, array $body, ?string $token = null): array
    {
        return $this->api($this->client, 'POST', "/api/plannings/{$this->s['planningId']}/periods/{$period}/end-rectification", $body + [
            'endsAt' => '2027-01-29',
            'expectedEndsAt' => '2027-01-31',
            'reason' => 'Le week-end du 29 au 31 janvier appartient entièrement à la période 2.',
            'confirm' => true,
        ], $token ?? $this->s['creator']);
    }

    private function assertError(int $status, string $code): void
    {
        self::assertResponseStatusCodeSame($status, (string) $this->client->getResponse()->getContent());
        self::assertSame($code, json_decode((string) $this->client->getResponse()->getContent(), true)['error'] ?? null, (string) $this->client->getResponse()->getContent());
    }

    /**
     * Fingerprints of everything a rectification must never change: duties, assignments and their events,
     * publications with their entries, lines and documents, generations, snapshots.
     *
     * @return array<string, string>
     */
    private function calendarFingerprint(): array
    {
        $db = $this->db();
        $md5 = static fn (string $sql): string => (string) $db->fetchOne("SELECT md5(coalesce(string_agg(t::text, '|' ORDER BY t::text), '')) FROM ({$sql}) t");

        return [
            'duties' => $md5('SELECT id, stable_id, local_date, starts_at, ends_at, planning_period_id, group_instance_id, pattern_id, duty_type_id FROM duties WHERE local_date < \'2027-01-29\''),
            'assignments' => $md5('SELECT * FROM duty_assignments'),
            'assignmentEvents' => $md5('SELECT * FROM duty_assignment_events'),
            'publications' => $md5('SELECT * FROM planning_publications'),
            'entries' => $md5('SELECT * FROM planning_publication_entries'),
            'publicationLines' => $md5('SELECT * FROM planning_publication_lines'),
            'documents' => $md5('SELECT id, publication_id, sha256, filename FROM planning_publication_documents'),
            'generations' => $md5('SELECT id, status, planning_period_id, snapshot_hash, coverage_status FROM planning_generations'),
            'snapshots' => $md5('SELECT * FROM planning_snapshots'),
            'groups' => $md5('SELECT id, anchor_date, planning_period_id, pattern_id FROM duty_group_instances WHERE anchor_date < \'2027-01-25\''),
        ];
    }

    // --- the Trauma Delta case ----------------------------------------------------------------------

    public function testTheBoundaryOfTraumaDeltaIsBroughtBackSoThatTheWeekendStaysWhole(): void
    {
        [$period1, $period2] = $this->traumaDelta();
        $db = $this->db();
        self::assertSame('2027-01-28', $db->fetchOne('SELECT MAX(d.local_date)::text FROM duties d JOIN planning_periods p ON p.id = d.planning_period_id JOIN planning_cycles c ON c.id = p.planning_cycle_id WHERE c.number = 1'));
        self::assertSame(0, (int) $db->fetchOne("SELECT COUNT(*) FROM duties WHERE local_date BETWEEN '2027-01-29' AND '2027-01-31'"), 'The weekend of 29-31 January is in neither period: the hole.');
        $dutiesBefore = (int) $db->fetchOne('SELECT COUNT(*) FROM duties');
        $before = $this->calendarFingerprint();

        // What the manager sees: only Thursday 28 as the new last day; Friday 29 would cut the weekend.
        $options = $this->options($period1);
        self::assertResponseIsSuccessful();
        self::assertTrue($options['eligible'], json_encode($options));
        self::assertSame(['2027-01-28', '2027-01-30', '2027-01-28'], [$options['lastDutyDay'], $options['currentLastDay'], $options['proposedLastDay']]);
        self::assertSame(['2027-01-28' => [], '2027-01-29' => ['period_boundary_splits_block']], array_combine(
            array_column($options['days'], 'date'),
            array_map(static fn (array $day): array => array_column($day['reasons'], 'code'), $options['days']),
        ));

        // Explicit, with a reason, on the dates read, never past a duty, never cutting the block.
        $this->rectify($period1, ['confirm' => false]);
        $this->assertError(422, 'rectification_confirmation_required');
        $this->rectify($period1, ['reason' => '   ']);
        $this->assertError(422, 'rectification_reason_required');
        $this->rectify($period1, ['expectedEndsAt' => '2027-01-30']);
        $this->assertError(409, 'period_changed');
        $this->rectify($period1, ['endsAt' => '2027-01-30']);
        $this->assertError(422, 'period_boundary_splits_block');
        $this->rectify($period1, ['endsAt' => '2027-01-28']);
        $this->assertError(409, 'period_has_duties_after');
        $this->rectify($period1, [], $this->s['admin']);
        self::assertResponseStatusCodeSame(403);
        self::assertSame('2027-01-31', $db->fetchOne('SELECT ends_at::text FROM planning_cycles WHERE number = 1'), 'Every refusal leaves the dates as they were.');

        $result = $this->rectify($period1, []);
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());
        self::assertSame(
            [['2027-01-01', '2027-01-28', 'published'], ['2027-01-29', '2027-05-02', 'preparation']],
            array_map(static fn (array $p): array => [$p['startsAt'], $p['lastDay'], $p['status']], $result['periods']),
        );
        self::assertSame(
            [[1, '2027-01-01', '2027-01-29', 'PUBLISHED'], [2, '2027-01-29', '2027-05-03', 'DRAFT']],
            array_map('array_values', $db->fetchAllAssociative('SELECT c.number, pp.starts_at::text, pp.ends_at::text, pp.status FROM planning_periods pp JOIN planning_cycles c ON c.id = pp.planning_cycle_id WHERE pp.planning_line_id IS NOT NULL ORDER BY c.number')),
        );
        self::assertSame($before, $this->calendarFingerprint(), 'Duties, assignments, publications, documents, generations and snapshots are untouched.');
        self::assertSame($dutiesBefore, (int) $db->fetchOne('SELECT COUNT(*) FROM duties'), 'No duty created, none lost.');
        $audit = static::getContainer()->get(PlanningCycleAudit::class);
        self::assertSame([], $audit->violations($audit->report()));

        // Traced, append-only.
        $journal = $db->fetchAssociative('SELECT r.previous_ends_at::text AS previous, r.new_ends_at::text AS new, r.last_duty_day::text AS last, r.reason, u.email FROM planning_cycle_rectifications r JOIN users u ON u.id = r.rectified_by_id');
        self::assertSame(['previous' => '2027-01-31', 'new' => '2027-01-29', 'last' => '2027-01-28', 'reason' => 'Le week-end du 29 au 31 janvier appartient entièrement à la période 2.', 'email' => 'creator@example.com'], $journal);
        $db->executeStatement('SAVEPOINT journal');
        try {
            $db->executeStatement("UPDATE planning_cycle_rectifications SET reason = 'x'");
            self::fail('The journal must refuse UPDATE.');
        } catch (\Throwable) {
            $db->executeStatement('ROLLBACK TO SAVEPOINT journal');
        }

        // Period 2 now starts on Friday 29: the weekend is materialized there whole, as an ordinary block.
        $preflight = $this->api($this->client, 'GET', "/api/plannings/{$this->s['planningId']}/generation-preflight?period={$period2}", token: $this->s['creator']);
        self::assertResponseIsSuccessful();
        self::assertNotContains('HISTORICAL_BLOCK_REMAINDER', array_column($preflight['warnings'], 'code'));
        self::assertNotContains('BLOCK_CUT_BY_PERIOD_BOUNDARY', array_column($preflight['blockers'], 'code'));
        self::assertSame(
            [['2027-01-29', 2], ['2027-01-30', 2], ['2027-01-31', 2]],
            array_map('array_values', $db->fetchAllAssociative("SELECT d.local_date::text, c.number FROM duties d JOIN planning_periods p ON p.id = d.planning_period_id JOIN planning_cycles c ON c.id = p.planning_cycle_id WHERE d.local_date BETWEEN '2027-01-29' AND '2027-01-31' ORDER BY d.local_date")),
        );
        self::assertSame(1, (int) $db->fetchOne("SELECT COUNT(DISTINCT group_instance_id) FROM duties WHERE local_date BETWEEN '2027-01-29' AND '2027-01-31'"), 'One block of three days, not a remainder.');
        self::assertSame($before, $this->calendarFingerprint(), 'Period 1 still untouched once period 2 is prepared.');

        // Done once: nothing is left to bring back.
        self::assertFalse($this->options($period1)['eligible']);
    }

    // --- refusals -------------------------------------------------------------------------------------

    public function testOnlyALockedPeriodFollowedByABlankOneIsRectified(): void
    {
        [$period1, $period2] = $this->traumaDelta();

        $this->rectify($period2, ['endsAt' => '2027-04-01', 'expectedEndsAt' => '2027-05-03']);
        $this->assertError(409, 'period_not_locked');
        self::assertFalse($this->options($period2)['eligible']);
        $this->options($period1, $this->s['alice']);
        self::assertResponseStatusCodeSame(403);

        // Period 2 prepared (duties materialized): the boundary no longer moves.
        $this->api($this->client, 'GET', "/api/plannings/{$this->s['planningId']}/generation-preflight?period={$period2}", token: $this->s['creator']);
        self::assertResponseIsSuccessful();
        $options = $this->options($period1);
        self::assertFalse($options['eligible']);
        self::assertSame(['next_period_not_blank'], array_column($options['blockers'], 'code'));
        $this->rectify($period1, []);
        $this->assertError(409, 'next_period_not_blank');
    }

    public function testACollectionOfTheLockedPeriodIsNeverLeftBeyondItsEnd(): void
    {
        [$period1] = $this->traumaDelta(withCollection: true);

        self::assertSame(['collection_beyond_end'], array_column($this->options($period1)['days'][0]['reasons'], 'code'));
        $this->rectify($period1, []);
        $this->assertError(409, 'collection_beyond_end');
    }

    public function testTheLastPeriodOfAPlanningHasNoBoundaryToRectify(): void
    {
        $period1 = $this->periods()[0]['stableId'];

        $options = $this->options($period1);
        self::assertSame(['period_not_locked'], array_column($options['blockers'], 'code'));
    }

    // --- prevention ---------------------------------------------------------------------------------

    public function testThePreflightSaysWhenTheEndOfTheLastPeriodCutsABlock(): void
    {
        $period1 = $this->periods()[0]['stableId'];
        $line = $this->api($this->client, 'GET', "/api/plannings/{$this->s['planningId']}", token: $this->s['creator'])['lines'][0]['stableId'];
        $this->api($this->client, 'PUT', "/api/planning-lines/{$line}/week-structure", self::STRUCTURE, $this->s['creator']);
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());

        // 2027-05-01 (exclusive): the last day is Friday 30 April, the weekend of 30 April - 2 May does not fit.
        $preflight = $this->api($this->client, 'GET', "/api/plannings/{$this->s['planningId']}/generation-preflight?period={$period1}", token: $this->s['creator']);
        self::assertResponseIsSuccessful();
        self::assertContains('PERIOD_END_CUTS_BLOCK', array_column($preflight['warnings'], 'code'));
        self::assertSame(0, (int) $this->db()->fetchOne("SELECT COUNT(*) FROM duties WHERE local_date = '2027-04-30'"));

        // Ending on Thursday 29 April instead: nothing cut, no warning.
        $this->db()->executeStatement("UPDATE availability_collections SET ends_at = '2027-04-30' WHERE stable_id = ?", [$this->s['collectionId']]);
        $this->db()->executeStatement("DELETE FROM duty_assignments WHERE duty_id IN (SELECT id FROM duties WHERE local_date >= '2027-04-30')");
        $this->api($this->client, 'PATCH', "/api/plannings/{$this->s['planningId']}/periods/{$period1}", ['endsAt' => '2027-04-30', 'expectedEndsAt' => '2027-05-01'], $this->s['creator']);
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());
        $preflight = $this->api($this->client, 'GET', "/api/plannings/{$this->s['planningId']}/generation-preflight?period={$period1}", token: $this->s['creator']);
        self::assertNotContains('PERIOD_END_CUTS_BLOCK', array_column($preflight['warnings'], 'code'));
    }

    public function testTheMigrationKeepsTheJournalOnceSomethingWasRectified(): void
    {
        require_once \dirname(__DIR__, 2).'/migrations/Version20261025090000.php';
        [$period1] = $this->traumaDelta();
        $this->rectify($period1, []);
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());

        $this->expectException(AbortMigration::class);
        (new Version20261025090000($this->db(), new NullLogger()))->down(new Schema());
    }
}
