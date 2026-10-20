<?php

declare(strict_types=1);

namespace App\Tests\Deployment;

use App\Service\PlanningCycleAudit;
use App\Tests\CalendarWorkflowTestHelpers;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use DoctrineMigrations\Version20261010180000;
use DoctrineMigrations\Version20261012090000;
use DoctrineMigrations\Version20261016090000;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The rollback guarantees of lots L1/L2 after what single-period
 * compatibility (docs/decisions.md D188) lets a planning go through while
 * MEDVUE_PERIOD_CREATION_ENABLED=0: a line added after the publication (it
 * gets its period, as before L1) and a published line deleted (its period
 * and history stay, attached to no line). Going down through L2, then L1,
 * then up again loses and changes nothing — the previous release can run on
 * the result. Same mechanics as PlanningCyclesMigrationTest (each
 * migration's own SQL inside the test transaction).
 */
// Migrations are not autoloaded outside the migrations tool.
require_once \dirname(__DIR__, 2).'/migrations/Version20261010180000.php';
require_once \dirname(__DIR__, 2).'/migrations/Version20261012090000.php';
require_once \dirname(__DIR__, 2).'/migrations/Version20261016090000.php';

final class SinglePeriodCompatibilityMigrationTest extends WebTestCase
{
    use CalendarWorkflowTestHelpers;

    private const FLAG = 'MEDVUE_PERIOD_CREATION_ENABLED';

    private Connection $db;

    /** @var array{0: mixed, 1: mixed} */
    private array $flag;

    protected function setUp(): void
    {
        $this->flag = [$_ENV[self::FLAG] ?? null, $_SERVER[self::FLAG] ?? null];
        $_ENV[self::FLAG] = $_SERVER[self::FLAG] = '0';
        $client = static::createClient();
        $s = $this->pilotScenario($client);
        $this->addSecondaryLine($client, $s, ['junior@example.com']);
        $this->prepareLine($s['planningId'], [['2027-01-05', '2027-01-06'], ['2027-01-12', '2027-01-13']]);
        $this->prepareLine($s['planningId'], [['2027-01-07', '2027-01-08']], lineIndex: 1);
        $this->generate($client, $s);
        $this->api($client, 'POST', "/api/plannings/{$s['planningId']}/publish", [], $s['creator']);
        self::assertResponseIsSuccessful();

        // After the publication: a line added (it gets its period) and the published "Juniors" deleted (orphan).
        $this->api($client, 'POST', "/api/plannings/{$s['planningId']}/lines", ['name' => 'Tardive'], $s['creator']);
        self::assertResponseStatusCodeSame(201);
        $juniors = $this->api($client, 'GET', "/api/plannings/{$s['planningId']}", token: $s['creator'])['lines'][1]['stableId'];
        $this->api($client, 'DELETE', "/api/plannings/{$s['planningId']}/lines/{$juniors}", token: $s['creator']);
        self::assertResponseStatusCodeSame(204);

        $this->db = static::getContainer()->get(Connection::class);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        [$_ENV[self::FLAG], $_SERVER[self::FLAG]] = $this->flag;
    }

    /**
     * Lot L4c (docs/decisions.md D196) comes after L2 and is undone first. Its down() refuses publications
     * recorded by line (what they published would be lost): the fixture is first brought back to what a database
     * before L4c holds — no publication line — so that L1 and L2 are tested on their own.
     */
    private function undoL4c(): void
    {
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

    /**
     * The published Juniors' duties, still held and not over, are never
     * silently ignored (docs/decisions.md D191): the audit reports them until
     * they are dealt with — before and after every migration alike.
     *
     * @return list<string>
     */
    private function expectedViolations(): array
    {
        $held = (int) $this->db->fetchOne("SELECT COUNT(*) FROM duty_assignments a JOIN duties d ON d.id = a.duty_id JOIN planning_periods pp ON pp.id = d.planning_period_id WHERE pp.name = 'Juniors' AND a.current AND d.ends_at > now()");
        self::assertGreaterThan(0, $held, 'fixture');

        return ['orphan_current_assignments_not_over: '.$held];
    }

    public function testTheFixtureHoldsALateLineAndAPublishedOrphan(): void
    {
        self::assertSame(
            ['Seniors' => 'PUBLISHED', 'Tardive' => 'DRAFT'],
            array_column($this->db->fetchAllAssociative('SELECT l.name, pp.status FROM planning_lines l JOIN planning_periods pp ON pp.planning_line_id = l.id ORDER BY l.name'), 'status', 'name'),
        );
        self::assertSame(
            ['status' => 'PUBLISHED', 'linked' => false, 'cycled' => true],
            $this->db->fetchAssociative("SELECT status, planning_line_id IS NOT NULL AS linked, planning_cycle_id IS NOT NULL AS cycled FROM planning_periods WHERE name = 'Juniors'"),
        );
        self::assertGreaterThan(0, (int) $this->db->fetchOne("SELECT COUNT(*) FROM planning_publication_entries e JOIN duties d ON d.id = e.duty_id JOIN planning_periods pp ON pp.id = d.planning_period_id WHERE pp.name = 'Juniors'"), 'the orphan keeps its publication history');
    }

    public function testL2DownThenUpLosesAndChangesNothing(): void
    {
        $audit = static::getContainer()->get(PlanningCycleAudit::class);
        $before = $audit->report();
        self::assertSame($this->expectedViolations(), $audit->violations($before));

        $this->migrate(Version20261012090000::class, 'down');
        $down = $audit->report();
        self::assertSame($before['lineLinks'], $down['lineLinks'], 'planning_lines.planning_period_id rebuilt for the late line too');
        self::assertSame([], $audit->compare($before, $down)['differences']);
        self::assertSame($this->expectedViolations(), $audit->violations($down));

        $this->migrate(Version20261012090000::class, 'up');
        $after = $audit->report();
        self::assertSame([], $audit->compare($before, $after)['differences']);
        self::assertSame([], $audit->compare($before, $after)['planned']);
        self::assertSame($this->expectedViolations(), $audit->violations($after));
    }

    public function testDownThroughL1ThenUpLosesAndChangesNothing(): void
    {
        $this->undoL4c();
        $audit = static::getContainer()->get(PlanningCycleAudit::class);
        $before = $audit->report();

        $this->migrate(Version20261012090000::class, 'down');
        $this->migrate(Version20261010180000::class, 'down');
        self::assertSame([], $audit->compare($audit->report(), $before)['differences']);

        $this->migrate(Version20261010180000::class, 'up');
        $this->migrate(Version20261012090000::class, 'up');
        $after = $audit->report();
        self::assertSame($this->expectedViolations(), $audit->violations($after));
        self::assertSame(['content changed: planning_cycles'], $audit->compare($before, $after)['differences'], 'only the regenerated cycles differ (new stable ids)');
        self::assertSame($before['lineLinks'], $after['lineLinks']);
        self::assertSame(1, (int) $this->db->fetchOne("SELECT COUNT(*) FROM planning_periods WHERE name = 'Juniors' AND planning_line_id IS NULL AND planning_cycle_id IS NOT NULL"), 'the orphan is back in its cycle, still without line');
    }
}
