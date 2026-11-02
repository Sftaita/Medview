<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Planning;
use Doctrine\DBAL\Connection;

/**
 * The visible calendar of a Planning, assignment by assignment
 * (docs/decisions.md D222): every `current` DutyAssignment of the most
 * recent COMPLETED generation of each period of each line (the D125 rule,
 * the one every reader applies), read straight from the database — never
 * from the EntityManager's identity map, which could hide a change.
 *
 * "Générer cette ligne" / "Générer les postes non pourvus" take it before
 * and after running: an assignment that existed before must still be
 * visible afterwards with the same id, duty, holder, source, lock and
 * generation. The difference is reported (never assumed to be 0), so the
 * guarantee is measured on every run, not only in the tests.
 */
final class CurrentCalendarLedger
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * @return array<int, array{stableId: string, dutyId: int, teamMemberId: int, source: string, locked: bool, generationId: int, lineId: int, periodId: int}> by assignment id
     */
    public function capture(Planning $planning): array
    {
        $rows = $this->connection->fetchAllAssociative(<<<'SQL'
            SELECT a.id, a.stable_id::text AS stable_id, a.duty_id, a.team_member_id, a.source, a.locked, a.generation_id, pp.planning_line_id AS line_id, pp.id AS period_id
            FROM duty_assignments a
            JOIN planning_generations g ON g.id = a.generation_id
            JOIN planning_periods pp ON pp.id = g.planning_period_id
            JOIN planning_lines pl ON pl.id = pp.planning_line_id
            WHERE pl.planning_id = :planning
              AND a.current
              AND g.id = (
                SELECT g2.id FROM planning_generations g2
                WHERE g2.planning_period_id = pp.id AND g2.status = 'COMPLETED'
                ORDER BY g2.created_at DESC, g2.id DESC
                LIMIT 1
              )
            SQL, ['planning' => $planning->getId()]);

        $ledger = [];
        foreach ($rows as $row) {
            $ledger[(int) $row['id']] = [
                'stableId' => (string) $row['stable_id'],
                'dutyId' => (int) $row['duty_id'],
                'teamMemberId' => (int) $row['team_member_id'],
                'source' => (string) $row['source'],
                'locked' => (bool) $row['locked'],
                'generationId' => (int) $row['generation_id'],
                'lineId' => (int) $row['line_id'],
                'periodId' => (int) $row['period_id'],
            ];
        }

        return $ledger;
    }

    /**
     * The assignments of $before that are no longer visible exactly as they
     * were — superseded, replaced, moved to another generation, or hidden by
     * a newer generation of their period.
     *
     * @param array<int, array<string, mixed>> $before
     * @param array<int, array<string, mixed>> $after
     *
     * @return list<int> assignment ids
     */
    public static function changed(array $before, array $after): array
    {
        $changed = [];
        foreach ($before as $id => $row) {
            if (($after[$id] ?? null) !== $row) {
                $changed[] = $id;
            }
        }

        return $changed;
    }
}
