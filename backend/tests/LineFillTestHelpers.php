<?php

declare(strict_types=1);

namespace App\Tests;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * "Générer les postes non pourvus" / "Générer cette ligne" (docs/decisions.md
 * D222), driven through the real API and the real worker handler, on top of
 * CalendarWorkflowTestHelpers' scenario.
 *
 * The integrity check is deliberately independent of the production code's
 * own ledger (CurrentCalendarLedger): every row of every table that holds a
 * calendar, its history, its snapshots or its publications is fingerprinted
 * (md5 of the whole row) before, and each row that existed before must be
 * byte-for-byte the same after. "Same count" is never enough: a row moved to
 * another person, superseded, or rewritten fails.
 */
trait LineFillTestHelpers
{
    use CalendarWorkflowTestHelpers;

    /** The tables whose existing rows a fill must never change. */
    private const PROTECTED_TABLES = [
        'duties',
        'duty_assignments',
        'duty_assignment_events',
        'planning_generations',
        'planning_snapshots',
        'planning_snapshot_members',
        'planning_snapshot_external_commitments',
        'planning_snapshot_availability_periods',
        'planning_publications',
        'planning_publication_entries',
        'planning_publication_lines',
        'planning_publication_documents',
        'planning_publication_notifications',
        'planning_publication_deliveries',
    ];

    /**
     * @return array<string, array<int, string>> table → row id → md5 of the whole row
     */
    private function protectedRows(): array
    {
        $connection = static::getContainer()->get(Connection::class);
        $state = [];
        foreach (self::PROTECTED_TABLES as $table) {
            $state[$table] = [];
            foreach ($connection->fetchAllNumeric("SELECT id, md5(t::text) FROM {$table} t") as [$id, $md5]) {
                $state[$table][(int) $id] = (string) $md5;
            }
        }

        return $state;
    }

    /**
     * The central assertion of D222: of the rows that existed before, none
     * was modified, deleted or replaced.
     *
     * @param array<string, array<int, string>> $before
     */
    private function assertNoExistingRowChanged(array $before, string $message = ''): void
    {
        $after = $this->protectedRows();
        $changed = [];
        foreach ($before as $table => $rows) {
            foreach ($rows as $id => $md5) {
                if (($after[$table][$id] ?? null) !== $md5) {
                    $changed[] = "{$table}#{$id}".(isset($after[$table][$id]) ? ' modified' : ' deleted');
                }
            }
        }
        self::assertSame([], $changed, 'Existing rows modified, deleted or replaced: 0 expected. '.$message);
    }

    /**
     * @param array<string, array<int, string>> $before
     */
    private function newRowCount(array $before, string $table): int
    {
        return \count(array_diff_key($this->protectedRows()[$table], $before[$table]));
    }

    /**
     * The visible calendar of line $lineIndex as the API shows it: duty stable id → [assignment stable id, holder].
     *
     * @param array<string, mixed> $s
     *
     * @return array<string, array{0: string, 1: string}|null>
     */
    private function visibleLine(KernelBrowser $client, array $s, int $lineIndex): array
    {
        $result = $this->api($client, 'GET', "/api/plannings/{$s['planningId']}/result", token: $s['creator']);
        self::assertResponseIsSuccessful();
        $line = $result['lines'][$lineIndex];
        $visible = [];
        foreach ($line['duties'] as $duty) {
            $visible[$duty['dutyStableId']] = null === $duty['assignment'] ? null : [$duty['assignment']['stableId'], $duty['assignment']['teamMemberStableId']];
        }
        ksort($visible);

        return $visible;
    }

    /**
     * @param array<string, mixed> $s
     *
     * @return list<string> the stable ids of the planning's lines, by position
     */
    private function lineIds(KernelBrowser $client, array $s): array
    {
        return array_column($this->api($client, 'GET', "/api/plannings/{$s['planningId']}", token: $s['creator'])['lines'], 'stableId');
    }

    /**
     * @param array<string, mixed> $s
     *
     * @return array<string, mixed>
     */
    private function fillPreview(KernelBrowser $client, array $s, ?string $token = null): array
    {
        return $this->api($client, 'GET', "/api/plannings/{$s['planningId']}/fill-preview", token: $token ?? $s['creator']);
    }

    /**
     * POST /fill then "the worker". Returns the finished job (202), or the refusal body.
     *
     * @param array<string, mixed> $s
     * @param list<string>         $lineIds
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    private function fillNow(KernelBrowser $client, array $s, array $lineIds, array $extra = [], ?string $token = null): array
    {
        $response = $this->api($client, 'POST', "/api/plannings/{$s['planningId']}/fill", ['lines' => $lineIds, ...$extra], $token ?? $s['creator']);
        if (202 !== $client->getResponse()->getStatusCode()) {
            return $response + ['httpStatus' => $client->getResponse()->getStatusCode()];
        }
        self::assertSame(1, $this->runQueuedPlanningJobs());

        return $this->api($client, 'GET', "/api/plannings/{$s['planningId']}/jobs/latest", token: $token ?? $s['creator'])['job'];
    }

    /**
     * @param array<string, mixed> $job
     *
     * @return array<string, array<string, mixed>> the job's per-line results, by line name
     */
    private function fillLines(array $job): array
    {
        return array_column($job['outcome']['lines'], null, 'lineName');
    }

    /** Count of current assignments per duty that is above one: never anything but 0. */
    private function duplicateCurrentAssignments(): int
    {
        return (int) static::getContainer()->get(Connection::class)->fetchOne(
            'SELECT COUNT(*) FROM (SELECT generation_id, duty_id FROM duty_assignments WHERE current GROUP BY 1, 2 HAVING COUNT(*) > 1) d',
        );
    }

    /**
     * Daily duties [08:00, 20:00) from $firstDay, $count of them — the "30 gardes" of the acceptance criterion.
     *
     * @return list<array{0: string, 1: string}>
     */
    private static function days(string $firstDay, int $count, string $from = '08:00', string $to = '20:00'): array
    {
        $ranges = [];
        $day = new \DateTimeImmutable($firstDay);
        for ($i = 0; $i < $count; ++$i) {
            $date = $day->modify("+{$i} days")->format('Y-m-d');
            $ranges[] = ["{$date} {$from}", "{$date} {$to}"];
        }

        return $ranges;
    }
}
