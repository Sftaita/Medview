<?php

declare(strict_types=1);

namespace App\Tests\Deployment;

use App\Service\PlanningCycleAudit;
use App\Tests\CalendarWorkflowTestHelpers;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20261016090000;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Lot L4c's migration (docs/decisions.md D196). Publications are recorded
 * through the real endpoints — a first publication, a republication that
 * also publishes a line for the first time, a republication announcing a
 * duty left uncovered — then the database is brought back to what a
 * release before L4c holds (no publication line, no cycle_complete): up()
 * must rebuild, from the frozen entries alone, exactly what L4c recorded
 * itself. Same mechanics as PlanningCyclesL3MigrationTest (the migration's
 * own SQL inside the test transaction).
 */
// Migrations are not autoloaded outside the migrations tool.
require_once \dirname(__DIR__, 2).'/migrations/Version20261016090000.php';

final class PlanningCyclesL4cMigrationTest extends WebTestCase
{
    use CalendarWorkflowTestHelpers;

    private KernelBrowser $client;

    /** @var array<string, mixed> */
    private array $s;

    private Connection $db;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->s = $this->pilotScenario($this->client);
        $this->db = static::getContainer()->get(Connection::class);

        // 1. Seniors published (Juniors, with nothing demanded yet, is neutral).
        $this->prepareLine($this->s['planningId'], [['2027-01-05', '2027-01-06'], ['2027-01-12', '2027-01-13']]);
        $team = $this->addSecondaryLine($this->client, $this->s, ['carol@example.com']);
        self::assertSame('SUCCEEDED', $this->generateNow($this->client, $this->s['planningId'], $this->s['creator'])['status']);
        $this->post('publish', []);
        // 2. Juniors gets a demand, is generated; Seniors changes; both go out together.
        $this->prepareLine($this->s['planningId'], [['2027-01-06', '2027-01-07']], lineIndex: 1);
        self::assertSame('SUCCEEDED', $this->generateNow($this->client, $this->s['planningId'], $this->s['creator'], ['scope' => 'unlocked_lines'])['status']);
        $duty = $this->dutyOn($this->s['planningId'], '2027-01-05');
        $current = $this->candidatesFor($this->client, $this->s, $duty)['currentTeamMemberStableId'] ?? null;
        $this->reassignTo($this->client, $this->s, $duty, $this->memberStableIdIn($this->s['planningId'], 'bob@example.com') === $current ? 'alice@example.com' : 'bob@example.com');
        self::assertResponseIsSuccessful();
        $juniors = $this->api($this->client, 'GET', "/api/plannings/{$this->s['planningId']}", token: $this->s['creator'])['lines'][1]['stableId'];
        $this->post('republish', ['newLines' => [$juniors]]);
        // 3. A Seniors duty left uncovered, announced.
        $this->unassignDuty($this->client, $this->s, $this->dutyOn($this->s['planningId'], '2027-01-12'));
        self::assertResponseIsSuccessful();
        $this->post('republish', []);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function post(string $action, array $body): void
    {
        $this->api($this->client, 'POST', "/api/plannings/{$this->s['planningId']}/{$action}", $body, $this->s['creator']);
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());
    }

    /**
     * @param 'up'|'down' $direction
     */
    private function migrate(string $direction): void
    {
        $migration = new Version20261016090000($this->db, new NullLogger());
        $migration->{$direction}(new Schema());
        foreach ($migration->getSql() as $query) {
            $this->db->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
    }

    /**
     * @return list<array<string, mixed>> per publication, oldest first: kind, cycle_complete, and the lines it recorded
     */
    private function recorded(): array
    {
        return $this->db->fetchAllAssociative(<<<'SQL'
            SELECT p.kind, p.cycle_complete, p.changed_duty_count,
                   (SELECT string_agg(concat_ws(':', l.planning_period_id, l.line_stable_id, l.line_name, l.kind, l.duty_count, l.changed_duty_count), ' ' ORDER BY l.planning_period_id)
                    FROM planning_publication_lines l WHERE l.publication_id = p.id) AS lines
            FROM planning_publications p ORDER BY p.published_at, p.id
        SQL);
    }

    /** Brings the database back to what a release before L4c holds: no publication line (L4c's down() then runs). */
    private function beforeL4c(): void
    {
        $this->db->executeStatement('ALTER TABLE planning_publication_lines DISABLE TRIGGER trg_planning_publication_lines_append_only');
        $this->db->executeStatement('DELETE FROM planning_publication_lines');
        $this->db->executeStatement('ALTER TABLE planning_publication_lines ENABLE TRIGGER trg_planning_publication_lines_append_only');
        $this->migrate('down');
    }

    public function testUpRebuildsFromTheFrozenEntriesExactlyWhatL4cRecords(): void
    {
        $recorded = $this->recorded();
        self::assertSame(['FIRST', 'UPDATE', 'UPDATE'], array_column($recorded, 'kind'), 'fixture');
        self::assertSame([true, true, false], array_column($recorded, 'cycle_complete'), 'fixture');
        self::assertStringContainsString('FIRST_FOR_LINE', (string) $recorded[1]['lines'], 'fixture: a line first published by a later publication');
        $audit = static::getContainer()->get(PlanningCycleAudit::class);
        $before = $audit->report();

        $this->beforeL4c();
        self::assertSame(0, (int) $this->db->fetchOne("SELECT COUNT(*) FROM information_schema.columns WHERE table_name = 'planning_publications' AND column_name = 'cycle_complete'"));
        $this->migrate('up');

        self::assertSame($recorded, $this->recorded(), 'kinds, counts, line identities and completeness, rebuilt identically');
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM planning_publication_lines WHERE NOT reconstructed'));
        self::assertSame(1, (int) $this->db->fetchOne("SELECT COUNT(*) FROM pg_trigger WHERE tgname = 'trg_planning_publications_append_only' AND tgenabled = 'O'"), 'append-only again');
        self::assertSame(1, (int) $this->db->fetchOne("SELECT COUNT(*) FROM pg_trigger WHERE tgname = 'trg_planning_publication_lines_append_only' AND tgenabled = 'O'"));
        // Nothing else moved: rows, constraints, indexes, triggers. The publication lines themselves are rebuilt rows
        // (new ids, `reconstructed`), compared field by field above.
        self::assertSame(['content changed: planning_publication_lines'], $audit->compare($before, $audit->report())['differences']);
    }

    public function testDownRefusesOncePublicationsWereRecordedByLine(): void
    {
        try {
            $this->migrate('down');
            self::fail('down() must refuse to lose which lines a publication published');
        } catch (DriverException $exception) {
            // The refusal aborts the migration's transaction: nothing is dropped.
            self::assertStringContainsString('Restore a backup instead', $exception->getMessage());
        }
    }

    public function testUpDownUpOnReconstructedRowsChangesNothing(): void
    {
        $this->beforeL4c();
        $this->migrate('up');
        $rebuilt = $this->recorded();

        $this->migrate('down');
        $this->migrate('up');

        self::assertSame($rebuilt, $this->recorded());
    }
}
