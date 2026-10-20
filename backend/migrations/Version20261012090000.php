<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Successive periods of a planning, lot L2 (docs/planning-cycles.md §3.4,
 * §6.1, docs/decisions.md D187): a line's periods are found through
 * `planning_periods.planning_line_id` only, so a line can have one period
 * per cycle.
 *
 * - Catch-up of anything a pre-L1 release could have written after a code
 *   rollback (a Planning without cycle, rows without cycle) — same rules as
 *   L1, a no-op otherwise.
 * - `planning_lines.planning_period_id` is dropped, after proving that
 *   `planning_periods.planning_line_id` holds exactly the same links.
 * - The temporary L1 leniencies end:
 *   - `planning_cycle_id` becomes NOT NULL on availability_collections,
 *     planning_jobs and planning_publications (reminders keep NULL for the
 *     ones sent before periods existed, as specified);
 *   - deleting a line no longer leaves its periods behind: the line FK of a
 *     period becomes RESTRICT (PlanningLineService deletes the periods of a
 *     line without history, and refuses otherwise);
 *   - a period of a line always has a cycle
 *     (`chk_planning_periods_cycle_of_line`, validated on every existing
 *     row): instead of the NOT NULL first planned, which would have to
 *     modify or delete the orphan periods already there (a line deleted
 *     before L2, dates matching no cycle) — they are kept exactly as they
 *     are, with no line and possibly no cycle (docs/decisions.md D187).
 *
 * Writes nothing to an append-only table unless the catch-up finds a
 * publication without cycle (then same disable/update/enable as L1, inside
 * this transaction). Row counts are checked at the end.
 *
 * down() rebuilds `planning_lines.planning_period_id` from the periods and
 * refuses, losing nothing, as soon as a planning has more than one cycle or
 * a line does not have exactly one period.
 */
final class Version20261012090000 extends AbstractMigration
{
    /** UUIDv7 from SQL, as in Version20261010180000. */
    private const UUID_V7 = "encode(set_bit(set_bit(overlay(uuid_send(gen_random_uuid()) placing substring(int8send(floor(extract(epoch from clock_timestamp()) * 1000)::bigint) from 3) from 1 for 6), 52, 1), 53, 1), 'hex')::uuid";

    private const COUNTED_TABLES = [
        'plannings', 'planning_cycles', 'planning_lines', 'planning_teams', 'planning_periods', 'fairness_periods',
        'availability_collections', 'availability_collection_responses', 'planning_jobs', 'planning_publications',
        'planning_availability_reminders', 'duties', 'duty_assignments', 'planning_generations',
    ];

    public function getDescription(): string
    {
        return 'Planning cycles L2: drop planning_lines.planning_period_id, cycle NOT NULL on collections/jobs/publications, RESTRICT line of a period, cycle required for a line period (D187)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TEMPORARY TABLE l2_counts ON COMMIT DROP AS '.implode(' UNION ALL ', array_map(
            static fn (string $table): string => \sprintf("SELECT '%1\$s'::text AS t, COUNT(*) AS n FROM %1\$s", $table),
            array_diff(self::COUNTED_TABLES, ['planning_cycles']),
        )));

        // --- catch-up (only after a rollback to a pre-L1 release; otherwise no row matches) ------
        $this->addSql('INSERT INTO planning_cycles (stable_id, planning_id, number, starts_at, ends_at, created_at, created_by_id) SELECT '.self::UUID_V7.', p.id, 1, p.starts_at, p.ends_at, p.created_at, NULL FROM plannings p WHERE NOT EXISTS (SELECT 1 FROM planning_cycles c WHERE c.planning_id = p.id) ORDER BY p.id');
        $this->addSql('UPDATE planning_periods pp SET planning_line_id = pl.id FROM planning_lines pl WHERE pl.planning_period_id = pp.id AND pp.planning_line_id IS NULL');
        $this->addSql(<<<'SQL'
            UPDATE planning_periods pp SET planning_cycle_id = c.id
            FROM planning_teams t, planning_cycles c
            WHERE pp.planning_cycle_id IS NULL AND t.id = pp.team_id AND c.planning_id = t.planning_id
              AND c.starts_at = pp.starts_at AND c.ends_at = pp.ends_at
        SQL);
        $this->addSql(<<<'SQL'
            UPDATE availability_collections a SET planning_cycle_id = c.id FROM planning_cycles c
            WHERE a.planning_cycle_id IS NULL AND c.planning_id = a.planning_id AND a.starts_at >= c.starts_at AND a.ends_at <= c.ends_at
        SQL);
        $this->addSql(<<<'SQL'
            UPDATE planning_jobs j SET planning_cycle_id = c.id FROM planning_cycles c
            WHERE j.planning_cycle_id IS NULL AND c.planning_id = j.planning_id
              AND (SELECT COUNT(*) FROM planning_cycles c2 WHERE c2.planning_id = j.planning_id) = 1
        SQL);
        $this->addSql(<<<'SQL'
            DO $$
            BEGIN
              IF EXISTS (SELECT 1 FROM planning_publications WHERE planning_cycle_id IS NULL) THEN
                EXECUTE 'ALTER TABLE planning_publications DISABLE TRIGGER trg_planning_publications_append_only';
                UPDATE planning_publications p SET planning_cycle_id = c.id FROM planning_cycles c
                  WHERE p.planning_cycle_id IS NULL AND c.planning_id = p.planning_id
                    AND (SELECT COUNT(*) FROM planning_cycles c2 WHERE c2.planning_id = p.planning_id) = 1;
                EXECUTE 'ALTER TABLE planning_publications ENABLE TRIGGER trg_planning_publications_append_only';
              END IF;
            END $$
        SQL);

        // --- the links about to be dropped are all held by planning_periods.planning_line_id -----
        $this->addSql(<<<'SQL'
            DO $$
            DECLARE n bigint;
            BEGIN
              SELECT COUNT(*) INTO n FROM planning_lines pl LEFT JOIN planning_periods pp ON pp.id = pl.planning_period_id
                WHERE pp.planning_line_id IS DISTINCT FROM pl.id OR pp.planning_cycle_id IS NULL;
              IF n > 0 THEN RAISE EXCEPTION 'L2: % line(s) whose period is not attached to the line and a cycle', n; END IF;

              SELECT COUNT(*) INTO n FROM planning_periods pp
                WHERE pp.planning_line_id IS NOT NULL
                  AND NOT EXISTS (SELECT 1 FROM planning_lines pl WHERE pl.planning_period_id = pp.id);
              IF n > 0 THEN RAISE EXCEPTION 'L2: % period(s) pointing at a line that does not point back', n; END IF;

              SELECT (SELECT COUNT(*) FROM availability_collections WHERE planning_cycle_id IS NULL)
                   + (SELECT COUNT(*) FROM planning_jobs WHERE planning_cycle_id IS NULL)
                   + (SELECT COUNT(*) FROM planning_publications WHERE planning_cycle_id IS NULL) INTO n;
              IF n > 0 THEN RAISE EXCEPTION 'L2: % collection/job/publication row(s) without a cycle', n; END IF;
            END $$
        SQL);

        // --- schema --------------------------------------------------------------------------------
        $this->addSql('ALTER TABLE planning_lines DROP CONSTRAINT fk_dead360944dba0dd');
        $this->addSql('DROP INDEX uniq_planning_lines_planning_period_id');
        $this->addSql('ALTER TABLE planning_lines DROP planning_period_id');

        $this->addSql('ALTER TABLE planning_periods DROP CONSTRAINT fk_planning_periods_line_team');
        $this->addSql('ALTER TABLE planning_periods ADD CONSTRAINT fk_planning_periods_line_team FOREIGN KEY (planning_line_id, team_id) REFERENCES planning_lines (id, planning_team_id) ON DELETE RESTRICT NOT DEFERRABLE');
        // A period of a line has its cycle (I3). Orphans (no line) are left as they are, never rewritten.
        $this->addSql('ALTER TABLE planning_periods ADD CONSTRAINT chk_planning_periods_cycle_of_line CHECK (planning_line_id IS NULL OR planning_cycle_id IS NOT NULL)');

        foreach (['availability_collections', 'planning_jobs', 'planning_publications'] as $table) {
            $this->addSql(\sprintf('ALTER TABLE %s ALTER planning_cycle_id SET NOT NULL', $table));
        }

        // --- row counts: nothing deleted, nothing added except catch-up cycles ------------------------
        $this->addSql(<<<'SQL'
            DO $$
            DECLARE missing bigint; actual bigint; r record;
            BEGIN
              FOR r IN SELECT c.t AS tbl, c.n AS before_count FROM l2_counts c LOOP
                EXECUTE format('SELECT COUNT(*) FROM %I', r.tbl) INTO actual;
                IF actual <> r.before_count THEN RAISE EXCEPTION 'L2: row count of % changed (% -> %)', r.tbl, r.before_count, actual; END IF;
              END LOOP;

              SELECT COUNT(*) INTO missing FROM plannings p
                WHERE NOT EXISTS (SELECT 1 FROM planning_cycles c WHERE c.planning_id = p.id);
              IF missing > 0 THEN RAISE EXCEPTION 'L2: % planning(s) without cycle', missing; END IF;
            END $$
        SQL);
    }

    public function down(Schema $schema): void
    {
        // Only a database where each line has its single period can go back (docs/planning-cycles.md §6.1).
        $this->addSql(<<<'SQL'
            DO $$
            BEGIN
              IF EXISTS (SELECT 1 FROM planning_cycles GROUP BY planning_id HAVING COUNT(*) > 1) THEN
                RAISE EXCEPTION 'A planning has more than one cycle: going back would lose periods. Restore a backup instead (docs/planning-cycles.md §6.1).';
              END IF;
              IF EXISTS (SELECT 1 FROM planning_lines pl WHERE (SELECT COUNT(*) FROM planning_periods pp WHERE pp.planning_line_id = pl.id) <> 1) THEN
                RAISE EXCEPTION 'A line does not have exactly one period (added while no period was open to it): the previous release cannot represent it. Restore a backup instead (docs/planning-cycles.md §6.1).';
              END IF;
            END $$
        SQL);

        foreach (['availability_collections', 'planning_jobs', 'planning_publications'] as $table) {
            $this->addSql(\sprintf('ALTER TABLE %s ALTER planning_cycle_id DROP NOT NULL', $table));
        }
        $this->addSql('ALTER TABLE planning_periods DROP CONSTRAINT chk_planning_periods_cycle_of_line');
        $this->addSql('ALTER TABLE planning_periods DROP CONSTRAINT fk_planning_periods_line_team');
        $this->addSql('ALTER TABLE planning_periods ADD CONSTRAINT fk_planning_periods_line_team FOREIGN KEY (planning_line_id, team_id) REFERENCES planning_lines (id, planning_team_id) ON DELETE SET NULL (planning_line_id) NOT DEFERRABLE');

        $this->addSql('ALTER TABLE planning_lines ADD planning_period_id INT DEFAULT NULL');
        $this->addSql('UPDATE planning_lines pl SET planning_period_id = pp.id FROM planning_periods pp WHERE pp.planning_line_id = pl.id');
        $this->addSql('ALTER TABLE planning_lines ALTER planning_period_id SET NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_planning_lines_planning_period_id ON planning_lines (planning_period_id)');
        $this->addSql('ALTER TABLE planning_lines ADD CONSTRAINT fk_dead360944dba0dd FOREIGN KEY (planning_period_id) REFERENCES planning_periods (id) ON DELETE RESTRICT NOT DEFERRABLE');
    }
}
