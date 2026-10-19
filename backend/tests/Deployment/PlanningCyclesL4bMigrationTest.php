<?php

declare(strict_types=1);

namespace App\Tests\Deployment;

use App\Service\PlanningCycleAudit;
use App\Tests\CalendarWorkflowTestHelpers;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20261014090000;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Lot L4b's migration (docs/decisions.md D195): one nullable column,
 * planning_jobs.generation_scope. Down then up on a generated planning
 * changes nothing — no job, no assignment, nothing the audit fingerprints;
 * every existing job reads NULL (the historical behaviour). Down refuses once
 * a job was requested with a scope (what was asked for, never dropped
 * silently). Same mechanics as PlanningCyclesL3MigrationTest.
 */
// Migrations are not autoloaded outside the migrations tool.
require_once \dirname(__DIR__, 2).'/migrations/Version20261014090000.php';

final class PlanningCyclesL4bMigrationTest extends WebTestCase
{
    use CalendarWorkflowTestHelpers;

    private Connection $db;

    protected function setUp(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);
        $this->prepareLine($s['planningId'], [['2027-01-05', '2027-01-06'], ['2027-01-12', '2027-01-13']]);
        $job = $this->generateNow($client, $s['planningId'], $s['creator']);
        self::assertSame('SUCCEEDED', $job['status'], 'fixture');
        $this->db = static::getContainer()->get(Connection::class);
    }

    /**
     * @param 'up'|'down' $direction
     */
    private function migrate(string $direction): void
    {
        $migration = new Version20261014090000($this->db, new NullLogger());
        $migration->{$direction}(new Schema());
        foreach ($migration->getSql() as $query) {
            $this->db->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
    }

    private function hasColumn(): bool
    {
        return 1 === (int) $this->db->fetchOne("SELECT COUNT(*) FROM information_schema.columns WHERE table_name = 'planning_jobs' AND column_name = 'generation_scope'");
    }

    /** Every job row, without the column the migration adds. */
    private function jobs(): array
    {
        return $this->db->fetchAllAssociative('SELECT id, stable_id, kind, status, rest_policy::text AS rest_policy, outcome::text AS outcome, planning_cycle_id FROM planning_jobs ORDER BY id');
    }

    private function assignments(): string
    {
        return (string) $this->db->fetchOne("SELECT md5(string_agg(ROW(a.*)::text, '|' ORDER BY a.id)) FROM duty_assignments a");
    }

    public function testDownThenUpChangesNothingAndLeavesEveryExistingJobUnscoped(): void
    {
        $audit = static::getContainer()->get(PlanningCycleAudit::class);
        $before = $audit->report();
        $jobs = $this->jobs();
        $assignments = $this->assignments();
        self::assertNotSame([], $jobs, 'fixture: a job exists');
        self::assertNotSame('', $assignments, 'fixture: assignments exist');

        $this->migrate('down');
        self::assertFalse($this->hasColumn());
        $this->migrate('up');

        self::assertTrue($this->hasColumn());
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM planning_jobs WHERE generation_scope IS NOT NULL'), 'existing jobs: the historical behaviour');
        self::assertSame($jobs, $this->jobs());
        self::assertSame($assignments, $this->assignments(), 'no historical assignment is touched');
        self::assertSame([], $audit->compare($before, $audit->report())['differences']);
    }

    public function testDownRefusesOnceAJobWasRequestedWithAScope(): void
    {
        $this->db->executeStatement("UPDATE planning_jobs SET generation_scope = 'unlocked_lines' WHERE id = (SELECT MIN(id) FROM planning_jobs)");

        try {
            $this->migrate('down');
            self::fail('down() must refuse to drop the scope a job was requested with');
        } catch (DriverException $exception) {
            // The refusal aborts the migration's transaction: the column is never dropped.
            self::assertStringContainsString('requested with a generation scope', $exception->getMessage());
        }
    }
}
