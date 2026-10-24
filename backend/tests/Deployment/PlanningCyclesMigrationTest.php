<?php

declare(strict_types=1);

namespace App\Tests\Deployment;

use App\Service\PlanningCycleAudit;
use App\Tests\CalendarWorkflowTestHelpers;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use DoctrineMigrations\Version20261010180000;
use DoctrineMigrations\Version20261012090000;
use DoctrineMigrations\Version20261016090000;
use DoctrineMigrations\Version20261024090000;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The migrations of the successive periods (docs/planning-cycles.md §6),
 * lot L1 (additive) and lot L2 (drops planning_lines.planning_period_id,
 * D187), replayed down then up on a database holding every kind of row they
 * touch — two lines, an extension (two collections), a generation job, a
 * publication, a reminder. Runs each migration class's own SQL inside the
 * test transaction (PostgreSQL DDL is transactional: everything is rolled
 * back afterwards).
 *
 * Proves: going down then up loses or changes nothing
 * (app:planning-cycles:audit fingerprints — only the regenerated cycle rows
 * differ, new stable ids; which period each line has is identical), the
 * append-only trigger is enabled again, and each down() refuses as soon as
 * it could lose something.
 */
// Migrations are not autoloaded outside the migrations tool.
require_once \dirname(__DIR__, 2).'/migrations/Version20261010180000.php';
require_once \dirname(__DIR__, 2).'/migrations/Version20261012090000.php';
require_once \dirname(__DIR__, 2).'/migrations/Version20261016090000.php';
require_once \dirname(__DIR__, 2).'/migrations/Version20261024090000.php';

final class PlanningCyclesMigrationTest extends WebTestCase
{
    use CalendarWorkflowTestHelpers;

    private Connection $db;

    /** @var array<string, mixed> */
    private array $scenario;

    protected function setUp(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);
        // A second line, before publication: it gets its period in cycle 1.
        $this->addSecondaryLine($client, $s, ['junior@example.com']);
        $this->api($client, 'POST', "/api/plannings/{$s['planningId']}/extensions", ['endsAt' => '2027-06-01'], $s['creator']);
        self::assertResponseIsSuccessful();
        $this->api($client, 'POST', "/api/plannings/{$s['planningId']}/reminders/pending", [], $s['creator']);
        self::assertResponseIsSuccessful();
        $this->prepareLine($s['planningId'], [['2027-01-05', '2027-01-06'], ['2027-01-12', '2027-01-13']]);
        $this->prepareLine($s['planningId'], [['2027-01-07', '2027-01-08']], lineIndex: 1);
        $this->generate($client, $s);
        $this->api($client, 'POST', "/api/plannings/{$s['planningId']}/publish", [], $s['creator']);
        self::assertResponseIsSuccessful();

        $this->db = static::getContainer()->get(Connection::class);
        $this->scenario = $s;
    }

    /**
     * Lot L4c (docs/decisions.md D196) comes after L2 and is undone first. Its down() refuses publications
     * recorded by line (what they published would be lost): the fixture is first brought back to what a database
     * before L4c holds — no publication line — so that L1 and L2 are tested on their own.
     */
    private function undoL4c(): void
    {
        // The period preparation (D210, nothing sent in this fixture) comes after L4c: undone first.
        $this->migrate(Version20261024090000::class, 'down');
        $this->db->executeStatement('ALTER TABLE planning_publication_lines DISABLE TRIGGER trg_planning_publication_lines_append_only');
        $this->db->executeStatement('DELETE FROM planning_publication_lines');
        $this->db->executeStatement('ALTER TABLE planning_publication_lines ENABLE TRIGGER trg_planning_publication_lines_append_only');
        $this->migrate(Version20261016090000::class, 'down');
    }

    /**
     * @param class-string<AbstractMigration> $class
     * @param 'up'|'down'                     $direction
     */
    private function migrate(string $class, string $direction): void
    {
        $migration = new $class($this->db, new NullLogger());
        $migration->{$direction}(new Schema());
        foreach ($migration->getSql() as $query) {
            $this->db->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
    }

    private function columnExists(string $table, string $column): bool
    {
        return 1 === (int) $this->db->fetchOne("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = 'public' AND table_name = ? AND column_name = ?", [$table, $column]);
    }

    public function testTheFixtureCoversEveryTableTheMigrationsTouch(): void
    {
        foreach (['planning_cycles', 'planning_periods', 'availability_collections', 'planning_jobs', 'planning_publications', 'planning_availability_reminders'] as $table) {
            self::assertGreaterThan(0, (int) $this->db->fetchOne(\sprintf('SELECT COUNT(*) FROM %s', $table)), $table);
        }
        self::assertSame(2, (int) $this->db->fetchOne('SELECT COUNT(*) FROM availability_collections'));
        self::assertSame(2, (int) $this->db->fetchOne('SELECT COUNT(*) FROM planning_periods WHERE planning_line_id IS NOT NULL'));
    }

    public function testL2DownThenUpKeepsEveryLineWithItsPeriod(): void
    {
        $audit = static::getContainer()->get(PlanningCycleAudit::class);
        $before = $audit->report();
        self::assertTrue($before['l2Schema']);
        self::assertSame([], $audit->violations($before));

        $this->migrate(Version20261012090000::class, 'down');
        self::assertTrue($this->columnExists('planning_lines', 'planning_period_id'));
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM planning_lines pl JOIN planning_periods pp ON pp.id = pl.planning_period_id WHERE pp.planning_line_id <> pl.id'));
        $down = $audit->report();
        self::assertFalse($down['l2Schema']);
        self::assertSame($before['lineLinks'], $down['lineLinks'], 'planning_lines.planning_period_id rebuilt exactly');
        $comparison = $audit->compare($before, $down);
        self::assertSame([], $comparison['differences']);
        self::assertSame([], $audit->violations($down));

        $this->migrate(Version20261012090000::class, 'up');
        $after = $audit->report();
        self::assertTrue($after['l2Schema']);
        self::assertSame([], $audit->compare($before, $after)['differences']);
        self::assertSame([], $audit->compare($before, $after)['planned'], 'the schema is back exactly');
        self::assertSame([], $audit->violations($after));
    }

    public function testDownThroughL1ThenUpLosesAndChangesNothing(): void
    {
        $this->undoL4c();
        $audit = static::getContainer()->get(PlanningCycleAudit::class);
        $before = $audit->report();

        $this->migrate(Version20261012090000::class, 'down');
        $this->migrate(Version20261010180000::class, 'down');
        self::assertFalse((bool) $this->db->fetchOne("SELECT to_regclass('public.planning_cycles') IS NOT NULL"));
        foreach (PlanningCycleAudit::L1_COLUMNS as $table => $columns) {
            foreach ($columns as $column) {
                self::assertFalse($this->columnExists($table, $column), "{$table}.{$column}");
            }
        }
        $down = $audit->report();
        self::assertFalse($down['l1Schema']);
        // Everything left is exactly what was there before (rows, constraints, indexes, triggers): the only gaps are
        // the objects of L1 and L2, which up() lists as additions or planned changes.
        self::assertSame([], $audit->compare($down, $before)['differences']);

        $this->migrate(Version20261010180000::class, 'up');
        $this->migrate(Version20261012090000::class, 'up');
        $after = $audit->report();
        self::assertSame([], $audit->violations($after));
        self::assertSame(['content changed: planning_cycles'], $audit->compare($before, $after)['differences'], 'only the regenerated cycles differ (new stable ids)');
        self::assertSame($before['statuses'], $after['statuses']);
        self::assertSame($before['lineLinks'], $after['lineLinks']);
        self::assertSame(1, (int) $this->db->fetchOne("SELECT COUNT(*) FROM pg_trigger WHERE tgname = 'trg_planning_publications_append_only' AND tgenabled = 'O'"));
        self::assertSame(
            0,
            (int) $this->db->fetchOne("SELECT COUNT(*) FROM planning_cycles WHERE substring(stable_id::text, 15, 1) <> '7'"),
            'cycles created by the migration have UUIDv7 stable ids',
        );
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM planning_cycles WHERE created_by_id IS NOT NULL'), 'migrated cycles have no author');
    }

    public function testL2DownRefusesOnceAPlanningHasASecondCycle(): void
    {
        $this->db->executeStatement('INSERT INTO planning_cycles (stable_id, planning_id, number, starts_at, ends_at, created_at) SELECT gen_random_uuid(), planning_id, 2, ends_at, ends_at + 30, NOW() FROM planning_cycles WHERE number = 1 LIMIT 1');

        try {
            $this->migrate(Version20261012090000::class, 'down');
            self::fail('down() must refuse to drop a second cycle');
        } catch (DriverException $e) {
            self::assertStringContainsString('more than one cycle', $e->getMessage());
        }
    }

    public function testL2DownRefusesALineWithoutPeriod(): void
    {
        $client = static::getClient();
        // Added after the publication: no period can take it (docs/planning-cycles.md §5.5).
        $this->api($client, 'POST', "/api/plannings/{$this->scenario['planningId']}/lines", ['name' => 'Tardive'], $this->scenario['creator']);
        self::assertResponseStatusCodeSame(201);

        try {
            $this->migrate(Version20261012090000::class, 'down');
            self::fail('down() must refuse a line the previous release cannot represent');
        } catch (DriverException $e) {
            self::assertStringContainsString('does not have exactly one period', $e->getMessage());
        }
    }
}
