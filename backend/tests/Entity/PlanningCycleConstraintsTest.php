<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Tests\CalendarWorkflowTestHelpers;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The database as the last line of defence for planning cycles
 * (docs/planning-cycles.md §3, migration Version20261010180000): every
 * statement below bypasses the ORM and the services.
 *
 * Fixture: one Planning 2027-01-01 → 2027-05-01 with two lines (Seniors,
 * Renfort), hence one cycle and two periods attached to it.
 */
final class PlanningCycleConstraintsTest extends WebTestCase
{
    use CalendarWorkflowTestHelpers;

    private Connection $db;
    private int $planningId;
    private int $cycleId;
    private int $userId;

    protected function setUp(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);
        $this->addSecondaryLine($client, $s, ['junior@example.com']);

        $this->db = static::getContainer()->get(Connection::class);
        $this->planningId = (int) $this->db->fetchOne('SELECT id FROM plannings WHERE stable_id = ?', [$s['planningId']]);
        $this->cycleId = (int) $this->db->fetchOne('SELECT id FROM planning_cycles WHERE planning_id = ?', [$this->planningId]);
        $this->userId = (int) $this->db->fetchOne("SELECT id FROM users WHERE email = 'creator@example.com'");
    }

    private function insertCycle(int $number, string $startsAt, string $endsAt, ?int $planningId = null): void
    {
        $this->db->executeStatement(
            'INSERT INTO planning_cycles (stable_id, planning_id, number, starts_at, ends_at, created_at, created_by_id) VALUES (gen_random_uuid(), ?, ?, ?, ?, NOW(), ?)',
            [$planningId ?? $this->planningId, $number, $startsAt, $endsAt, $this->userId],
        );
    }

    /**
     * @return array{id: int, team_id: int, line_id: int, fairness_period_id: int}
     */
    private function line(int $position): array
    {
        $row = $this->db->fetchAssociative(
            'SELECT pp.id, pp.team_id, pl.id AS line_id, pp.fairness_period_id FROM planning_lines pl JOIN planning_periods pp ON pp.planning_line_id = pl.id WHERE pl.planning_id = ? AND pl.position = ?',
            [$this->planningId, $position],
        );
        \assert(false !== $row);

        return ['id' => (int) $row['id'], 'team_id' => (int) $row['team_id'], 'line_id' => (int) $row['line_id'], 'fairness_period_id' => (int) $row['fairness_period_id']];
    }

    private function otherPlanningId(): int
    {
        $this->db->executeStatement("INSERT INTO plannings (stable_id, name, creator_id, starts_at, ends_at, timezone, created_at, updated_at) VALUES (gen_random_uuid(), 'Autre', ?, '2027-01-01', '2027-05-01', 'Europe/Brussels', NOW(), NOW())", [$this->userId]);

        return (int) $this->db->fetchOne("SELECT id FROM plannings WHERE name = 'Autre'");
    }

    public function testTheFixtureHasOneCycleHoldingBothPeriods(): void
    {
        self::assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM planning_cycles WHERE planning_id = ?', [$this->planningId]));
        self::assertSame(2, (int) $this->db->fetchOne('SELECT COUNT(*) FROM planning_periods WHERE planning_cycle_id = ?', [$this->cycleId]));
    }

    public function testCyclesOfOnePlanningNeverOverlap(): void
    {
        $this->expectException(DriverException::class);
        $this->expectExceptionMessageMatches('/excl_planning_cycles_no_overlap/');
        $this->insertCycle(2, '2027-04-01', '2027-06-01');
    }

    public function testAFollowingCycleIsAcceptedAndANumberIsNeverReused(): void
    {
        $this->insertCycle(2, '2027-05-01', '2027-09-01');
        self::assertSame(2, (int) $this->db->fetchOne('SELECT COUNT(*) FROM planning_cycles WHERE planning_id = ?', [$this->planningId]));

        $this->expectException(UniqueConstraintViolationException::class);
        $this->insertCycle(2, '2027-09-01', '2027-12-01');
    }

    public function testAnotherPlanningMayUseTheSameDatesAndNumber(): void
    {
        $this->insertCycle(1, '2027-01-01', '2027-05-01', $this->otherPlanningId());

        self::assertSame(2, (int) $this->db->fetchOne("SELECT COUNT(*) FROM planning_cycles WHERE number = 1 AND starts_at = '2027-01-01'"));
    }

    public function testACycleHasAPositiveNumberAndRange(): void
    {
        foreach ([[0, '2027-05-01', '2027-06-01'], [2, '2027-06-01', '2027-06-01'], [2, '2027-07-01', '2027-06-01']] as [$number, $startsAt, $endsAt]) {
            $this->db->executeStatement('SAVEPOINT bad_cycle');
            try {
                $this->insertCycle($number, $startsAt, $endsAt);
                self::fail(\sprintf('expected a CHECK violation for #%d %s → %s', $number, $startsAt, $endsAt));
            } catch (DriverException $e) {
                self::assertMatchesRegularExpression('/chk_planning_cycles_(number|dates)/', $e->getMessage());
            }
            $this->db->executeStatement('ROLLBACK TO SAVEPOINT bad_cycle');
        }
    }

    public function testAPeriodCannotPointAtTheLineOfAnotherTeam(): void
    {
        $primary = $this->line(1);
        $secondary = $this->line(2);
        // Moved to a second cycle in the same statement so that the (line, cycle) uniqueness cannot answer first.
        $this->insertCycle(2, '2027-05-01', '2027-09-01');
        $cycle2 = (int) $this->db->fetchOne('SELECT id FROM planning_cycles WHERE planning_id = ? AND number = 2', [$this->planningId]);

        $this->expectException(ForeignKeyConstraintViolationException::class);
        $this->db->executeStatement('UPDATE planning_periods SET planning_line_id = ?, planning_cycle_id = ? WHERE id = ?', [$primary['line_id'], $cycle2, $secondary['id']]);
    }

    /** Lot L2 (D187): the period of a line always has its cycle; only an orphan may have none. */
    public function testThePeriodOfALineAlwaysHasACycle(): void
    {
        $secondary = $this->line(2);

        $this->db->executeStatement('SAVEPOINT detach');
        try {
            $this->db->executeStatement('UPDATE planning_periods SET planning_cycle_id = NULL WHERE id = ?', [$secondary['id']]);
            self::fail('a period of a line accepted no cycle');
        } catch (DriverException $e) {
            self::assertStringContainsString('chk_planning_periods_cycle_of_line', $e->getMessage());
        }
        $this->db->executeStatement('ROLLBACK TO SAVEPOINT detach');

        // A historical orphan (no line) keeps working as it is: without cycle, untouched.
        $this->db->executeStatement(
            "INSERT INTO planning_periods (stable_id, team_id, fairness_period_id, name, starts_at, ends_at, status, created_at, updated_at) VALUES (gen_random_uuid(), ?, ?, 'Orpheline', '2026-01-01', '2026-02-01', 'DRAFT', NOW(), NOW())",
            [$secondary['team_id'], $secondary['fairness_period_id']],
        );
        self::assertSame(1, (int) $this->db->fetchOne("SELECT COUNT(*) FROM planning_periods WHERE name = 'Orpheline' AND planning_line_id IS NULL AND planning_cycle_id IS NULL"));
    }

    public function testALineHasAtMostOnePeriodPerCycle(): void
    {
        $primary = $this->line(1);
        $this->db->executeStatement("UPDATE fairness_periods SET ends_at = '2027-09-01' WHERE id = ?", [$primary['fairness_period_id']]);

        $this->expectException(UniqueConstraintViolationException::class);
        $this->db->executeStatement(
            "INSERT INTO planning_periods (stable_id, team_id, fairness_period_id, name, starts_at, ends_at, status, created_at, updated_at, planning_cycle_id, planning_line_id) VALUES (gen_random_uuid(), ?, ?, 'Double', '2027-05-01', '2027-09-01', 'DRAFT', NOW(), NOW(), ?, ?)",
            [$primary['team_id'], $primary['fairness_period_id'], $this->cycleId, $primary['line_id']],
        );
    }

    public function testPeriodsOfOneTeamNeverOverlap(): void
    {
        $primary = $this->line(1);

        $this->expectException(DriverException::class);
        $this->expectExceptionMessageMatches('/excl_planning_periods_team_no_overlap/');
        $this->db->executeStatement(
            "INSERT INTO planning_periods (stable_id, team_id, fairness_period_id, name, starts_at, ends_at, status, created_at, updated_at) VALUES (gen_random_uuid(), ?, ?, 'Chevauche', '2027-02-01', '2027-03-01', 'DRAFT', NOW(), NOW())",
            [$primary['team_id'], $primary['fairness_period_id']],
        );
    }

    /**
     * Lot L2 (D187): deleting a line no longer leaves orphan periods behind —
     * the database refuses while the line has periods; PlanningLineService
     * deletes the periods of a line without history first.
     */
    public function testALineCannotBeDeletedWhileItHasPeriods(): void
    {
        $secondary = $this->line(2);
        $this->db->executeStatement('DELETE FROM planning_line_demand_policies WHERE target_line_id = ?', [$secondary['line_id']]);

        $this->expectException(ForeignKeyConstraintViolationException::class);
        $this->expectExceptionMessageMatches('/fk_planning_periods_line_team/');
        $this->db->executeStatement('DELETE FROM planning_lines WHERE id = ?', [$secondary['line_id']]);
    }

    /** Lot L2 (D187): no NULL cycle on a collection, a job or a publication any more. */
    public function testCollectionsJobsAndPublicationsAlwaysHaveACycle(): void
    {
        foreach (['availability_collections', 'planning_jobs', 'planning_publications'] as $table) {
            self::assertSame('NO', $this->db->fetchOne("SELECT is_nullable FROM information_schema.columns WHERE table_name = ? AND column_name = 'planning_cycle_id'", [$table]), $table);
        }
        self::assertSame('YES', $this->db->fetchOne("SELECT is_nullable FROM information_schema.columns WHERE table_name = 'planning_availability_reminders' AND column_name = 'planning_cycle_id'"), 'reminders sent before periods existed keep NULL');
    }

    public function testACollectionJobPublicationOrReminderCannotUseACycleOfAnotherPlanning(): void
    {
        $other = $this->otherPlanningId();
        $this->insertCycle(1, '2027-01-01', '2027-05-01', $other);
        $foreignCycle = (int) $this->db->fetchOne('SELECT id FROM planning_cycles WHERE planning_id = ?', [$other]);

        $statements = [
            'availability_collections' => ['UPDATE availability_collections SET planning_cycle_id = ? WHERE planning_id = ?', [$foreignCycle, $this->planningId]],
            'planning_jobs' => ["INSERT INTO planning_jobs (stable_id, kind, status, created_at, planning_id, requested_by_id, planning_cycle_id) VALUES (gen_random_uuid(), 'GENERATE', 'SUCCEEDED', NOW(), ?, ?, ?)", [$this->planningId, $this->userId, $foreignCycle]],
            'planning_publications' => ["INSERT INTO planning_publications (stable_id, planning_id, kind, published_by_id, changed_duty_count, published_at, planning_cycle_id, cycle_complete) VALUES (gen_random_uuid(), ?, 'FIRST', ?, 0, NOW(), ?, TRUE)", [$this->planningId, $this->userId, $foreignCycle]],
            'planning_availability_reminders' => ["INSERT INTO planning_availability_reminders (stable_id, channel, bulk, pending_collection_count, sent_at, planning_id, recipient_id, sent_by_id, planning_cycle_id) VALUES (gen_random_uuid(), 'EMAIL', false, 1, NOW(), ?, ?, ?, ?)", [$this->planningId, $this->userId, $this->userId, $foreignCycle]],
        ];

        foreach ($statements as $table => [$sql, $params]) {
            $this->db->executeStatement('SAVEPOINT foreign_cycle');
            try {
                $this->db->executeStatement($sql, $params);
                self::fail(\sprintf('%s accepted a cycle of another planning', $table));
            } catch (ForeignKeyConstraintViolationException $e) {
                self::assertStringContainsString('fk_'.$table.'_cycle_planning', $e->getMessage());
            }
            $this->db->executeStatement('ROLLBACK TO SAVEPOINT foreign_cycle');
        }
    }

    public function testOneFirstPublicationPerCycle(): void
    {
        $insert = 'INSERT INTO planning_publications (stable_id, planning_id, kind, published_by_id, changed_duty_count, published_at, planning_cycle_id, cycle_complete) VALUES (gen_random_uuid(), ?, ?, ?, 0, NOW(), ?, TRUE)';
        $this->db->executeStatement($insert, [$this->planningId, 'FIRST', $this->userId, $this->cycleId]);
        $this->db->executeStatement($insert, [$this->planningId, 'UPDATE', $this->userId, $this->cycleId]);

        $this->expectException(UniqueConstraintViolationException::class);
        $this->db->executeStatement($insert, [$this->planningId, 'FIRST', $this->userId, $this->cycleId]);
    }

    public function testPublicationsAreStillAppendOnlyAfterTheBackfill(): void
    {
        $this->db->executeStatement("INSERT INTO planning_publications (stable_id, planning_id, kind, published_by_id, changed_duty_count, published_at, planning_cycle_id, cycle_complete) VALUES (gen_random_uuid(), ?, 'FIRST', ?, 0, NOW(), ?, TRUE)", [$this->planningId, $this->userId, $this->cycleId]);

        $this->expectException(DriverException::class);
        $this->expectExceptionMessageMatches('/append-only/');
        $this->db->executeStatement('UPDATE planning_publications SET planning_cycle_id = planning_cycle_id WHERE planning_id = ?', [$this->planningId]);
    }

    public function testTheCycleOfAPlanningCannotBeDeletedWhileRowsUseIt(): void
    {
        $this->expectException(ForeignKeyConstraintViolationException::class);
        $this->db->executeStatement('DELETE FROM planning_cycles WHERE id = ?', [$this->cycleId]);
    }
}
