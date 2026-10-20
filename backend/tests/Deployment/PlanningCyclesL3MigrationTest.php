<?php

declare(strict_types=1);

namespace App\Tests\Deployment;

use App\Service\PlanningCycleAudit;
use App\Tests\CalendarWorkflowTestHelpers;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20261013090000;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Lot L3's migration (docs/decisions.md D189): four nullable columns on
 * planning_snapshot_external_commitments. Down then up on a generated
 * planning changes nothing; down refuses once a snapshot froze a commitment
 * of another period (a frozen value, never dropped silently). Same mechanics
 * as PlanningCyclesMigrationTest (the migration's own SQL inside the test
 * transaction).
 */
// Migrations are not autoloaded outside the migrations tool.
require_once \dirname(__DIR__, 2).'/migrations/Version20261013090000.php';

final class PlanningCyclesL3MigrationTest extends WebTestCase
{
    use CalendarWorkflowTestHelpers;

    private Connection $db;

    protected function setUp(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);
        // Every Seniors member is on Juniors too (D160): whoever holds the Seniors duty is frozen in Juniors' snapshot.
        $teamId = $this->addSecondaryLine($client, $s, ['junior@example.com']);
        foreach (['admin@example.com', 'alice@example.com', 'bob@example.com'] as $email) {
            $this->addMemberAs($client, $s['creator'], $s['planningId'], $teamId, $email, 'MEMBER');
        }
        $this->prepareLine($s['planningId'], [['2027-01-05', '2027-01-06']]);
        $this->prepareLine($s['planningId'], [['2027-01-07', '2027-01-08']], lineIndex: 1);
        $this->generate($client, $s);
        $this->db = static::getContainer()->get(Connection::class);
    }

    /**
     * @param 'up'|'down' $direction
     */
    private function migrate(string $direction): void
    {
        $migration = new Version20261013090000($this->db, new NullLogger());
        $migration->{$direction}(new Schema());
        foreach ($migration->getSql() as $query) {
            $this->db->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
    }

    private function columns(): array
    {
        return $this->db->fetchFirstColumn("SELECT column_name FROM information_schema.columns WHERE table_name = 'planning_snapshot_external_commitments' AND column_name LIKE 'source_cycle%' ORDER BY column_name");
    }

    public function testDownThenUpChangesNothing(): void
    {
        $audit = static::getContainer()->get(PlanningCycleAudit::class);
        $before = $audit->report();
        $rows = $this->db->fetchAllAssociative('SELECT id, source_user_stable_id, source_duty_stable_id FROM planning_snapshot_external_commitments ORDER BY id');

        $this->migrate('down');
        self::assertSame([], $this->columns());
        $this->migrate('up');

        self::assertSame(['source_cycle_ends_at', 'source_cycle_number', 'source_cycle_stable_id', 'source_cycle_starts_at'], $this->columns());
        self::assertSame($rows, $this->db->fetchAllAssociative('SELECT id, source_user_stable_id, source_duty_stable_id FROM planning_snapshot_external_commitments ORDER BY id'));
        self::assertSame([], $audit->compare($before, $audit->report())['differences']);
    }

    public function testDownRefusesOnceACommitmentOfAnotherPeriodIsFrozen(): void
    {
        $this->db->executeStatement('UPDATE planning_snapshot_external_commitments SET source_cycle_stable_id = gen_random_uuid(), source_cycle_number = 2 WHERE id = (SELECT MIN(id) FROM planning_snapshot_external_commitments)');
        self::assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM planning_snapshot_external_commitments WHERE source_cycle_stable_id IS NOT NULL'), 'fixture');

        try {
            $this->migrate('down');
            self::fail('down() must refuse to drop a frozen period');
        } catch (DriverException $exception) {
            self::assertStringContainsString('froze a commitment of another period', $exception->getMessage());
        }
    }
}
