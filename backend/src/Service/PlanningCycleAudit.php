<?php

declare(strict_types=1);

namespace App\Service;

use Doctrine\DBAL\Connection;

/**
 * Read-only integrity audit of the successive-periods migration
 * (docs/planning-cycles.md §6.4, app:planning-cycles:audit).
 *
 * report() takes a fingerprint of the whole database — every table's row
 * count and an order-independent hash of every row, every constraint, index
 * and trigger — plus the planning-cycle invariants once the L1 schema is
 * there. compare() then proves that a migration lost or changed nothing:
 * same rows (columns added by L1 and dropped by L2 excluded from the hash,
 * the line ↔ period links compared on their own), same constraints and
 * indexes, triggers still enabled; additions and the schema changes L2
 * makes on purpose are listed, never errors.
 *
 * Only counts, hashes and schema definitions are produced — never a row's
 * content, so a report can be shared without leaking personal data or
 * secrets.
 */
final class PlanningCycleAudit
{
    /**
     * Columns added by lot L1: excluded from the row hashes so that a report
     * taken before the migration compares with one taken after.
     */
    public const L1_COLUMNS = [
        'planning_periods' => ['planning_cycle_id', 'planning_line_id'],
        'availability_collections' => ['planning_cycle_id'],
        'planning_jobs' => ['planning_cycle_id'],
        'planning_publications' => ['planning_cycle_id'],
        'planning_availability_reminders' => ['planning_cycle_id'],
    ];

    /**
     * Columns added by the later lots — L3 (D189), L4b (D195), L4c (D196) —
     * excluded the same way, so that a report taken before the whole chain
     * compares with one taken after it. What they hold is checked by each
     * migration's own assertions and tests (L4c: the backfill of
     * cycle_complete), never by these hashes.
     */
    public const LATER_COLUMNS = [
        'planning_snapshot_external_commitments' => ['source_cycle_stable_id', 'source_cycle_number', 'source_cycle_starts_at', 'source_cycle_ends_at'],
        'planning_jobs' => ['generation_scope'],
        'planning_publications' => ['cycle_complete'],
        'planning_publication_notifications' => ['first_lines'],
    ];

    /** Tables added by lot L1: reported, not compared with a report taken before it. */
    public const L1_TABLES = ['planning_cycles'];

    /**
     * Column dropped by lot L2 (docs/decisions.md D187): excluded from the row
     * hashes too. What it held — which period each line had — is compared
     * separately ("lineLinks"), read from wherever the link lives.
     */
    public const L2_DROPPED_COLUMNS = [
        'planning_lines' => ['planning_period_id'],
    ];

    /**
     * The schema objects lot L2 removes or redefines on purpose (and its
     * down() restores): listed by compare() as "planned", never as
     * differences — every other one still is.
     */
    public const L2_PLANNED_CHANGES = [
        'constraint' => [
            'planning_lines.fk_dead360944dba0dd',
            'planning_periods.fk_planning_periods_line_team',
            'planning_periods.chk_planning_periods_cycle_of_line',
        ],
        'index' => [
            'planning_lines.uniq_planning_lines_planning_period_id',
        ],
    ];

    /** Written by the migration tool itself, never business data. */
    private const IGNORED_TABLES = ['doctrine_migration_versions'];

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @return array{
     *     migrationVersion: string|null,
     *     l1Schema: bool,
     *     l2Schema: bool,
     *     lineLinks: array{count: int, fingerprint: string}|null,
     *     tables: array<string, array{rows: int, fingerprint: string}>,
     *     constraints: array<string, string>,
     *     indexes: array<string, string>,
     *     triggers: array<string, string>,
     *     statuses: array<string, array<string, int>>,
     *     invariants: array<string, array{count: int, blocking: bool}>
     * }
     */
    public function report(): array
    {
        $l1Schema = $this->tableExists('planning_cycles');
        $l2Schema = $l1Schema && !$this->columnExists('planning_lines', 'planning_period_id');

        return [
            'migrationVersion' => $this->migrationVersion(),
            'l1Schema' => $l1Schema,
            'l2Schema' => $l2Schema,
            'lineLinks' => $this->lineLinks($l1Schema, $l2Schema),
            'tables' => $this->tables(),
            'constraints' => $this->constraints(),
            'indexes' => $this->indexes(),
            'triggers' => $this->triggers(),
            'statuses' => $this->statuses(),
            'invariants' => $l1Schema ? $this->invariants($l2Schema) : [],
        ];
    }

    /**
     * Blocking invariant violations of a report.
     *
     * @param array{invariants: array<string, array{count: int, blocking: bool}>, triggers: array<string, string>} $report
     *
     * @return list<string>
     */
    public function violations(array $report): array
    {
        $problems = [];
        foreach ($report['invariants'] as $code => $invariant) {
            if ($invariant['blocking'] && $invariant['count'] > 0) {
                $problems[] = \sprintf('%s: %d', $code, $invariant['count']);
            }
        }
        foreach ($report['triggers'] as $name => $definition) {
            if (!str_starts_with($definition, 'enabled ')) {
                $problems[] = \sprintf('trigger not enabled: %s', $name);
            }
        }

        return $problems;
    }

    /**
     * @param array{tables: array<string, array{rows: int, fingerprint: string}>, constraints: array<string, string>, indexes: array<string, string>, triggers: array<string, string>} $before
     * @param array{tables: array<string, array{rows: int, fingerprint: string}>, constraints: array<string, string>, indexes: array<string, string>, triggers: array<string, string>} $after
     *
     * @return array{differences: list<string>, additions: list<string>, planned: list<string>}
     */
    public function compare(array $before, array $after): array
    {
        $differences = [];
        $additions = [];
        $planned = [];

        // Which period each line has: the same before and after, wherever the link is stored (L2).
        $linksBefore = $before['lineLinks'] ?? null;
        $linksAfter = $after['lineLinks'] ?? null;
        if (\is_array($linksBefore) && \is_array($linksAfter) && $linksBefore !== $linksAfter) {
            $differences[] = \sprintf('line period links changed (%d → %d)', $linksBefore['count'], $linksAfter['count']);
        }

        foreach ($before['tables'] as $table => $state) {
            $now = $after['tables'][$table] ?? null;
            if (null === $now) {
                $differences[] = \sprintf('table removed: %s', $table);
            } elseif ($now['rows'] !== $state['rows']) {
                $differences[] = \sprintf('row count changed: %s (%d → %d)', $table, $state['rows'], $now['rows']);
            } elseif ($now['fingerprint'] !== $state['fingerprint']) {
                $differences[] = \sprintf('content changed: %s', $table);
            }
        }
        foreach (array_diff_key($after['tables'], $before['tables']) as $table => $state) {
            $additions[] = \sprintf('table added: %s (%d rows)', $table, $state['rows']);
        }

        foreach (['constraints' => 'constraint', 'indexes' => 'index', 'triggers' => 'trigger'] as $key => $label) {
            $plannedNames = self::L2_PLANNED_CHANGES[$label] ?? [];
            foreach ($before[$key] as $name => $definition) {
                $change = match (true) {
                    !\array_key_exists($name, $after[$key]) => \sprintf('%s removed: %s', $label, $name),
                    $after[$key][$name] !== $definition => \sprintf('%s changed: %s', $label, $name),
                    default => null,
                };
                if (null === $change) {
                    continue;
                }
                if (\in_array($name, $plannedNames, true)) {
                    $planned[] = $change;
                } else {
                    $differences[] = $change;
                }
            }
            foreach (array_diff_key($after[$key], $before[$key]) as $name => $definition) {
                $additions[] = \sprintf('%s added: %s', $label, $name);
            }
        }

        return ['differences' => $differences, 'additions' => $additions, 'planned' => $planned];
    }

    private function tableExists(string $table): bool
    {
        return (bool) $this->connection->fetchOne('SELECT to_regclass(?) IS NOT NULL', ['public.'.$table]);
    }

    private function columnExists(string $table, string $column): bool
    {
        return (bool) $this->connection->fetchOne("SELECT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = 'public' AND table_name = ? AND column_name = ?)", [$table, $column]);
    }

    /**
     * Fingerprint of the (line, period) pairs: from planning_lines before L2,
     * from planning_periods.planning_line_id after it. Null before L1 (the
     * pairs are then the planning_lines rows themselves, already hashed).
     *
     * @return array{count: int, fingerprint: string}|null
     */
    private function lineLinks(bool $l1Schema, bool $l2Schema): ?array
    {
        if (!$l1Schema) {
            return null;
        }
        $pairs = $l2Schema
            ? 'SELECT planning_line_id AS l, id AS p FROM planning_periods WHERE planning_line_id IS NOT NULL'
            : 'SELECT id AS l, planning_period_id AS p FROM planning_lines';
        $row = $this->connection->fetchAssociative(\sprintf("SELECT COUNT(*) AS n, md5(coalesce(string_agg(l || ':' || p, ',' ORDER BY l, p), '')) AS f FROM (%s) s", $pairs));
        \assert(false !== $row);

        return ['count' => (int) $row['n'], 'fingerprint' => (string) $row['f']];
    }

    private function migrationVersion(): ?string
    {
        if (!$this->tableExists('doctrine_migration_versions')) {
            return null;
        }
        $version = $this->connection->fetchOne('SELECT version FROM doctrine_migration_versions ORDER BY version DESC LIMIT 1');

        return false === $version ? null : (string) $version;
    }

    /**
     * @return array<string, array{rows: int, fingerprint: string}>
     */
    private function tables(): array
    {
        $tables = $this->connection->fetchFirstColumn("SELECT table_name FROM information_schema.tables WHERE table_schema = 'public' AND table_type = 'BASE TABLE' ORDER BY table_name");
        $result = [];
        foreach ($tables as $table) {
            $table = (string) $table;
            if (\in_array($table, self::IGNORED_TABLES, true)) {
                continue;
            }
            $excluded = [...(self::L1_COLUMNS[$table] ?? []), ...(self::L2_DROPPED_COLUMNS[$table] ?? []), ...(self::LATER_COLUMNS[$table] ?? [])];
            $columns = array_values(array_filter(
                array_map('strval', $this->connection->fetchFirstColumn("SELECT column_name FROM information_schema.columns WHERE table_schema = 'public' AND table_name = ? ORDER BY ordinal_position", [$table])),
                static fn (string $column): bool => !\in_array($column, $excluded, true),
            ));
            $row = 'ROW('.implode(', ', array_map(fn (string $column): string => 't.'.$this->connection->quoteSingleIdentifier($column), $columns)).')::text';
            $state = $this->connection->fetchAssociative(\sprintf(
                "SELECT COUNT(*) AS n, md5(coalesce(string_agg(h, '' ORDER BY h), '')) AS f FROM (SELECT md5(%s) AS h FROM %s t) s",
                $row,
                $this->connection->quoteSingleIdentifier($table),
            ));
            \assert(false !== $state);
            $result[$table] = ['rows' => (int) $state['n'], 'fingerprint' => (string) $state['f']];
        }

        return $result;
    }

    /**
     * @return array<string, string> "table.constraint" => definition
     */
    private function constraints(): array
    {
        $rows = $this->connection->fetchAllAssociative(<<<'SQL'
            SELECT c.conrelid::regclass::text AS t, c.conname AS name, pg_get_constraintdef(c.oid) AS def
            FROM pg_constraint c JOIN pg_namespace n ON n.oid = c.connamespace
            WHERE n.nspname = 'public' AND c.conrelid <> 0
            ORDER BY 1, 2
        SQL);

        return $this->keyed($rows);
    }

    /**
     * @return array<string, string>
     */
    private function indexes(): array
    {
        $rows = $this->connection->fetchAllAssociative("SELECT tablename AS t, indexname AS name, indexdef AS def FROM pg_indexes WHERE schemaname = 'public' ORDER BY 1, 2");

        return $this->keyed($rows);
    }

    /**
     * @return array<string, string> "table.trigger" => "enabled|disabled <definition>"
     */
    private function triggers(): array
    {
        $rows = $this->connection->fetchAllAssociative(<<<'SQL'
            SELECT tg.tgrelid::regclass::text AS t, tg.tgname AS name,
                   CASE tg.tgenabled WHEN 'D' THEN 'disabled ' ELSE 'enabled ' END || pg_get_triggerdef(tg.oid) AS def
            FROM pg_trigger tg JOIN pg_class c ON c.oid = tg.tgrelid JOIN pg_namespace n ON n.oid = c.relnamespace
            WHERE n.nspname = 'public' AND NOT tg.tgisinternal
            ORDER BY 1, 2
        SQL);

        return $this->keyed($rows);
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return array<string, string>
     */
    private function keyed(array $rows): array
    {
        $result = [];
        foreach ($rows as $row) {
            $result[$row['t'].'.'.$row['name']] = (string) $row['def'];
        }

        return $result;
    }

    /**
     * @return array<string, array<string, int>>
     */
    private function statuses(): array
    {
        $queries = [
            'planning_periods.status' => 'SELECT status AS k, COUNT(*) AS n FROM planning_periods GROUP BY 1',
            'planning_generations.status' => 'SELECT status AS k, COUNT(*) AS n FROM planning_generations GROUP BY 1',
            'availability_collections.status' => 'SELECT status AS k, COUNT(*) AS n FROM availability_collections GROUP BY 1',
            'planning_jobs.status' => 'SELECT status AS k, COUNT(*) AS n FROM planning_jobs GROUP BY 1',
            'planning_publications.kind' => 'SELECT kind AS k, COUNT(*) AS n FROM planning_publications GROUP BY 1',
            'duty_assignments.source' => 'SELECT source AS k, COUNT(*) AS n FROM duty_assignments GROUP BY 1',
        ];
        $result = [];
        foreach ($queries as $label => $sql) {
            $counts = [];
            foreach ($this->connection->fetchAllAssociative($sql.' ORDER BY 1') as $row) {
                $counts[(string) $row['k']] = (int) $row['n'];
            }
            $result[$label] = $counts;
        }

        return $result;
    }

    /**
     * docs/planning-cycles.md §2. Non-blocking ones describe expected
     * leftovers (periods of lines deleted before L2, reminders sent before
     * periods existed, lines waiting for their first period) or facts.
     *
     * @return array<string, array{count: int, blocking: bool}>
     */
    private function invariants(bool $l2Schema): array
    {
        $checks = [
            // I1 — every Planning has its cycles numbered 1..n.
            'plannings_without_cycle' => [true, 'SELECT COUNT(*) FROM plannings p WHERE NOT EXISTS (SELECT 1 FROM planning_cycles c WHERE c.planning_id = p.id)'],
            'cycle_numbering_gaps' => [true, 'SELECT COUNT(*) FROM (SELECT planning_id FROM planning_cycles GROUP BY planning_id HAVING MAX(number) <> COUNT(*)) s'],
            // I2 — contiguous: each cycle n+1 starts where cycle n ends.
            'cycles_not_contiguous' => [true, 'SELECT COUNT(*) FROM planning_cycles a JOIN planning_cycles b ON b.planning_id = a.planning_id AND b.number = a.number + 1 WHERE b.starts_at <> a.ends_at'],
            // I5 — the Planning's range is the envelope of its cycles.
            'planning_envelope_mismatch' => [true, 'SELECT COUNT(*) FROM plannings p JOIN (SELECT planning_id, MIN(starts_at) s, MAX(ends_at) e FROM planning_cycles GROUP BY planning_id) c ON c.planning_id = p.id WHERE p.starts_at <> c.s OR p.ends_at <> c.e'],
            // I3 — every line's period is attached to that line and to a cycle of its Planning, with the cycle's dates.
            'line_periods_not_attached' => [true, $l2Schema
                ? 'SELECT COUNT(*) FROM planning_periods WHERE planning_line_id IS NOT NULL AND planning_cycle_id IS NULL'
                : 'SELECT COUNT(*) FROM planning_lines pl JOIN planning_periods pp ON pp.id = pl.planning_period_id WHERE pp.planning_line_id IS DISTINCT FROM pl.id OR pp.planning_cycle_id IS NULL'],
            'periods_line_mismatch' => [true, $l2Schema
                ? 'SELECT COUNT(*) FROM planning_periods pp JOIN planning_lines pl ON pl.id = pp.planning_line_id WHERE pl.planning_team_id <> pp.team_id'
                : 'SELECT COUNT(*) FROM planning_periods pp JOIN planning_lines pl ON pl.id = pp.planning_line_id WHERE pl.planning_period_id <> pp.id'],
            // A period of a line lies inside its team's FairnessPeriod (§3.5).
            'periods_outside_fairness' => [true, 'SELECT COUNT(*) FROM planning_periods pp JOIN fairness_periods f ON f.id = pp.fairness_period_id WHERE pp.planning_line_id IS NOT NULL AND (pp.starts_at < f.starts_at OR pp.ends_at > f.ends_at)'],
            // An orphan (line deleted, history kept, D188) keeps the dates it had: the cycle may have moved since.
            'periods_cycle_mismatch' => [true, 'SELECT COUNT(*) FROM planning_periods pp JOIN planning_cycles c ON c.id = pp.planning_cycle_id JOIN planning_teams t ON t.id = pp.team_id WHERE t.planning_id <> c.planning_id OR (pp.planning_line_id IS NOT NULL AND (pp.starts_at <> c.starts_at OR pp.ends_at <> c.ends_at))'],
            // I12 — every collection in one cycle of its Planning.
            'collections_without_cycle' => [true, 'SELECT COUNT(*) FROM availability_collections WHERE planning_cycle_id IS NULL'],
            'collections_outside_cycle' => [true, 'SELECT COUNT(*) FROM availability_collections a JOIN planning_cycles c ON c.id = a.planning_cycle_id WHERE a.starts_at < c.starts_at OR a.ends_at > c.ends_at'],
            'jobs_without_cycle' => [true, 'SELECT COUNT(*) FROM planning_jobs WHERE planning_cycle_id IS NULL'],
            'publications_without_cycle' => [true, 'SELECT COUNT(*) FROM planning_publications WHERE planning_cycle_id IS NULL'],
            // Expected leftovers, reported only.
            'orphan_periods_without_line' => [false, 'SELECT COUNT(*) FROM planning_periods WHERE planning_line_id IS NULL'],
            'orphan_periods_without_cycle' => [false, 'SELECT COUNT(*) FROM planning_periods WHERE planning_line_id IS NULL AND planning_cycle_id IS NULL'],
            // The duties of a deleted line still "held" in its period's current generation and not over yet: no screen,
            // email or engine reads them any more (docs/decisions.md D190). Blocking (D191): never silently ignored —
            // each one needs an explicit decision before the audit passes.
            'orphan_current_assignments_not_over' => [true, <<<'SQL'
                SELECT COUNT(*) FROM planning_periods pp
                JOIN planning_generations g ON g.id = (
                    SELECT g2.id FROM planning_generations g2 WHERE g2.planning_period_id = pp.id AND g2.status = 'COMPLETED'
                    ORDER BY g2.created_at DESC, g2.id DESC LIMIT 1)
                JOIN duty_assignments a ON a.generation_id = g.id AND a.current
                JOIN duties d ON d.id = a.duty_id
                WHERE pp.planning_line_id IS NULL AND d.ends_at > now()
                SQL],
            'reminders_without_cycle' => [false, 'SELECT COUNT(*) FROM planning_availability_reminders WHERE planning_cycle_id IS NULL'],
            'plannings_with_several_cycles' => [false, 'SELECT COUNT(*) FROM (SELECT planning_id FROM planning_cycles GROUP BY planning_id HAVING COUNT(*) > 1) s'],
            'lines_without_period' => [false, 'SELECT COUNT(*) FROM planning_lines pl WHERE NOT EXISTS (SELECT 1 FROM planning_periods pp WHERE pp.planning_line_id = pl.id)'],
            'cycles_shorter_than_7_days' => [false, 'SELECT COUNT(*) FROM planning_cycles WHERE ends_at - starts_at < 7'],
        ];

        $result = [];
        foreach ($checks as $code => [$blocking, $sql]) {
            $result[$code] = ['count' => (int) $this->connection->fetchOne($sql), 'blocking' => $blocking];
        }

        return $result;
    }
}
