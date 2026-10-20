<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Service\PlanningCycleAudit;
use App\Tests\CalendarWorkflowTestHelpers;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * app:planning-cycles:audit (docs/planning-cycles.md §6.4): passes on a
 * consistent database, and catches exactly what a lossy migration would do —
 * a changed or removed row, a removed constraint, a trigger left disabled, a
 * period detached from its cycle. Every alteration below happens in the
 * test's own transaction (DDL included) and is rolled back.
 */
final class PlanningCyclesAuditCommandTest extends WebTestCase
{
    use CalendarWorkflowTestHelpers;

    private Connection $db;
    private CommandTester $command;
    private string $reportPath;

    protected function setUp(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);
        $this->addSecondaryLine($client, $s, ['junior@example.com']);

        $this->db = static::getContainer()->get(Connection::class);
        $this->command = new CommandTester((new Application(static::$kernel))->find('app:planning-cycles:audit'));
        $this->reportPath = sys_get_temp_dir().'/planning-cycles-audit-'.bin2hex(random_bytes(6)).'.json';
    }

    protected function tearDown(): void
    {
        if (is_file($this->reportPath)) {
            unlink($this->reportPath);
        }
        parent::tearDown();
    }

    /** The console output with its line wrapping undone. */
    private function display(): string
    {
        return (string) preg_replace('/\s+/', ' ', $this->command->getDisplay());
    }

    public function testAConsistentDatabasePassesAndTheReportHoldsNoRowContent(): void
    {
        self::assertSame(Command::SUCCESS, $this->command->execute(['--output' => $this->reportPath]));
        self::assertStringContainsString('Audit passed', $this->display());

        $content = (string) file_get_contents($this->reportPath);
        $report = json_decode($content, true, 512, \JSON_THROW_ON_ERROR);
        self::assertTrue($report['l1Schema']);
        self::assertArrayHasKey('planning_cycles', $report['tables']);
        self::assertArrayNotHasKey('doctrine_migration_versions', $report['tables']);
        self::assertSame(0, $report['invariants']['line_periods_not_attached']['count']);
        // Only counts, hashes and schema: no email, no name, no token.
        self::assertStringNotContainsString('@example.com', $content);
        self::assertStringNotContainsString('Seniors', $content);
    }

    public function testComparingWithItselfFindsNoDifference(): void
    {
        $this->command->execute(['--output' => $this->reportPath]);

        self::assertSame(Command::SUCCESS, $this->command->execute(['--compare' => $this->reportPath]));
        self::assertStringContainsString('(no additions)', $this->display());
    }

    public function testAChangedRowARemovedRowAndARemovedConstraintAreReported(): void
    {
        $this->command->execute(['--output' => $this->reportPath]);

        $this->db->executeStatement("UPDATE plannings SET name = 'Renommé en douce'");
        $this->db->executeStatement('DELETE FROM availability_collection_responses WHERE id = (SELECT MIN(id) FROM availability_collection_responses)');
        $this->db->executeStatement('ALTER TABLE planning_cycles DROP CONSTRAINT chk_planning_cycles_number');

        self::assertSame(Command::FAILURE, $this->command->execute(['--compare' => $this->reportPath]));
        $display = $this->display();
        self::assertStringContainsString('content changed: plannings', $display);
        self::assertStringContainsString('row count changed: availability_collection_responses', $display);
        self::assertStringContainsString('constraint removed: planning_cycles.chk_planning_cycles_number', $display);
    }

    public function testColumnsAddedByL1AreLeftOutOfTheRowHashesButTheLinePeriodLinksAreCompared(): void
    {
        $this->db->executeStatement('INSERT INTO planning_cycles (stable_id, planning_id, number, starts_at, ends_at, created_at) SELECT gen_random_uuid(), planning_id, 2, ends_at, ends_at + 30, NOW() FROM planning_cycles WHERE number = 1 LIMIT 1');
        $audit = static::getContainer()->get(PlanningCycleAudit::class);
        $before = $audit->report();
        self::assertTrue($before['l2Schema']);
        self::assertSame(2, $before['lineLinks']['count']);

        // Only an L1 column changes (the cycle of a period): a report taken before the migration still compares equal.
        $this->db->executeStatement('UPDATE planning_periods SET planning_cycle_id = (SELECT MAX(id) FROM planning_cycles) WHERE id = (SELECT MAX(id) FROM planning_periods WHERE planning_line_id IS NOT NULL)');
        self::assertSame([], $audit->compare($before, $audit->report())['differences']);

        // Which period each line has is never left out (it replaced planning_lines.planning_period_id, D187).
        $this->db->executeStatement('UPDATE planning_periods SET planning_line_id = NULL WHERE id = (SELECT MAX(id) FROM planning_periods WHERE planning_line_id IS NOT NULL)');
        self::assertSame(['line period links changed (2 → 1)'], $audit->compare($before, $audit->report())['differences']);
    }

    public function testADisabledTriggerAndAPeriodOffItsCycleFailTheAudit(): void
    {
        $this->db->executeStatement('ALTER TABLE planning_publications DISABLE TRIGGER trg_planning_publications_append_only');
        $this->db->executeStatement('UPDATE planning_periods SET ends_at = ends_at - 1 WHERE id = (SELECT MIN(id) FROM planning_periods WHERE planning_line_id IS NOT NULL)');

        self::assertSame(Command::FAILURE, $this->command->execute([]));
        $display = $this->display();
        self::assertStringContainsString('trigger not enabled: planning_publications.trg_planning_publications_append_only', $display);
        self::assertStringContainsString('periods_cycle_mismatch: 1', $display);
    }

    /** The schema changes of lot L2 are reported as planned, any other removal still as a difference. */
    public function testTheSchemaChangesOfL2AreListedAsPlanned(): void
    {
        $audit = static::getContainer()->get(PlanningCycleAudit::class);
        $after = $audit->report();
        $before = $after;
        $before['constraints']['planning_lines.fk_dead360944dba0dd'] = 'FOREIGN KEY (planning_period_id) REFERENCES planning_periods(id) ON DELETE RESTRICT';
        $before['indexes']['planning_lines.uniq_planning_lines_planning_period_id'] = 'CREATE UNIQUE INDEX …';
        $before['constraints']['planning_periods.fk_planning_periods_line_team'] .= ' ON DELETE SET NULL (planning_line_id)';
        $before['constraints']['plannings.some_other_constraint'] = 'CHECK (true)';

        $comparison = $audit->compare($before, $after);

        self::assertEqualsCanonicalizing([
            'constraint removed: planning_lines.fk_dead360944dba0dd',
            'constraint changed: planning_periods.fk_planning_periods_line_team',
            'index removed: planning_lines.uniq_planning_lines_planning_period_id',
        ], $comparison['planned']);
        self::assertSame(['constraint removed: plannings.some_other_constraint'], $comparison['differences']);
    }

    public function testAPlanningWithoutCycleOrWithAGapIsReported(): void
    {
        $userId = (int) $this->db->fetchOne('SELECT MIN(id) FROM users');
        $this->db->executeStatement("INSERT INTO plannings (stable_id, name, creator_id, starts_at, ends_at, timezone, created_at, updated_at) VALUES (gen_random_uuid(), 'Ancienne version', ?, '2027-01-01', '2027-05-01', 'Europe/Brussels', NOW(), NOW())", [$userId]);
        $planningId = (int) $this->db->fetchOne('SELECT MIN(planning_id) FROM planning_cycles');
        $this->db->executeStatement("INSERT INTO planning_cycles (stable_id, planning_id, number, starts_at, ends_at, created_at) VALUES (gen_random_uuid(), ?, 2, '2027-06-01', '2027-09-01', NOW())", [$planningId]);

        $invariants = static::getContainer()->get(PlanningCycleAudit::class)->report()['invariants'];

        self::assertSame(1, $invariants['plannings_without_cycle']['count']);
        self::assertSame(1, $invariants['cycles_not_contiguous']['count']);
        self::assertSame(1, $invariants['planning_envelope_mismatch']['count']);
        self::assertSame(1, $invariants['plannings_with_several_cycles']['count']);
    }
}
