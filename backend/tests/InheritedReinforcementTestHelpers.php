<?php

declare(strict_types=1);

namespace App\Tests;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * docs/decisions.md D224 — a "Première ligne" with a real weekly structure
 * (a Friday–Sunday block and a standalone Tuesday) and a "Fell/Ass"
 * reinforcement line that inherits its blocks. The triggers are chosen so
 * each surgeon produces one case of the block rule:
 *
 * - admin: Friday, Saturday, Sunday and Tuesday → a three-day block (and the Tuesday);
 * - alice: Saturday only → one standalone Saturday;
 * - bob: Friday and Sunday → a two-day block, never a Saturday.
 *
 * Fell/Ass members: carol, dave, and alice too — so the rule "never the
 * holder of the source duty" is really exercised.
 *
 * The using class also uses LineFillTestHelpers (pilot scenario, fill, md5 of rows).
 */
trait InheritedReinforcementTestHelpers
{
    private const TRIGGERS = [
        'admin@example.com' => ['FRIDAY', 'SATURDAY', 'SUNDAY', 'TUESDAY'],
        'alice@example.com' => ['SATURDAY'],
        'bob@example.com' => ['FRIDAY', 'SUNDAY'],
    ];

    /**
     * @return array<string, mixed> the pilot scenario plus primaryLineId, fellLineId
     */
    private function inheritedScenario(KernelBrowser $client): array
    {
        $s = $this->pilotScenario($client);
        $this->api($client, 'POST', "/api/plannings/{$s['planningId']}/lines", ['name' => 'Fell/Ass'], $s['creator']);
        self::assertResponseStatusCodeSame(201);
        $lines = $this->api($client, 'GET', "/api/plannings/{$s['planningId']}", token: $s['creator'])['lines'];
        $s['primaryLineId'] = $lines[0]['stableId'];
        $s['fellLineId'] = $lines[1]['stableId'];
        $s['carol'] = $this->userToken($client, 'carol@example.com');
        $s['dave'] = $this->userToken($client, 'dave@example.com');
        foreach (['carol@example.com', 'dave@example.com', 'alice@example.com'] as $email) {
            $this->addMemberAs($client, $s['creator'], $s['planningId'], $lines[1]['team']['stableId'], $email, 'MEMBER');
        }
        $this->api($client, 'PUT', "/api/planning-lines/{$s['primaryLineId']}/week-structure", [
            'blocks' => [['name' => 'Week-end', 'days' => ['VEN', 'SAM', 'DIM'], 'family' => 'Week-end']],
            'solo' => ['MAR'],
            'soloFamily' => 'Semaine',
            'excluded' => ['LUN', 'MER', 'JEU'],
        ], $s['creator']);
        self::assertResponseIsSuccessful();
        foreach ([$s['primaryLineId'], $s['fellLineId']] as $line) {
            $this->api($client, 'POST', "/api/planning-lines/{$line}/rule-set/activate", [], $s['creator']);
            self::assertTrue(\in_array($client->getResponse()->getStatusCode(), [200, 201], true));
        }

        return $s;
    }

    /** @return array<string, mixed> the PUT body of the Fell/Ass configuration */
    private function fellPolicy(array $s, array $triggers = self::TRIGGERS): array
    {
        $users = static::getContainer()->get(\App\Repository\UserRepository::class);

        return [
            'schemaVersion' => 1,
            'mode' => 'CONDITIONAL_ON_SOURCE_ASSIGNMENT',
            'source' => ['lineStableId' => $s['primaryLineId']],
            'triggers' => array_map(
                static fn (string $email, array $days): array => ['userStableId' => (string) $users->findOneByEmail($email)->getStableId(), 'weekdays' => $days, 'increment' => 1],
                array_keys($triggers),
                array_values($triggers),
            ),
        ];
    }

    private function configureFell(KernelBrowser $client, array $s, array $triggers = self::TRIGGERS): array
    {
        $view = $this->api($client, 'PUT', "/api/planning-lines/{$s['fellLineId']}/demand-policy", $this->fellPolicy($s, $triggers), $s['creator']);
        self::assertResponseIsSuccessful();

        return $view;
    }

    /**
     * Every Fell/Ass duty with what it reinforces: one row per duty.
     *
     * @return list<array{id: int, date: string, weekday: int, block: ?int, holder: ?string, assignmentId: ?int, source: int, sourceBlock: ?int, sourceHolder: ?string, sameInstants: bool}>
     */
    private function fellRows(): array
    {
        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'date' => (string) $r['date'],
            'weekday' => (int) $r['weekday'],
            'block' => null !== $r['block'] ? (int) $r['block'] : null,
            'holder' => $r['holder'],
            'assignmentId' => null !== $r['assignment_id'] ? (int) $r['assignment_id'] : null,
            'source' => (int) $r['source'],
            'sourceBlock' => null !== $r['source_block'] ? (int) $r['source_block'] : null,
            'sourceHolder' => $r['source_holder'],
            'sameInstants' => (bool) $r['same_instants'],
        ], static::getContainer()->get(Connection::class)->fetchAllAssociative(<<<'SQL'
            SELECT d.id, d.local_date AS date, EXTRACT(ISODOW FROM d.local_date) AS weekday, d.group_instance_id AS block,
                   hu.email AS holder, ha.id AS assignment_id,
                   s.id AS source, s.group_instance_id AS source_block, su.email AS source_holder,
                   (d.starts_at = s.starts_at AND d.ends_at = s.ends_at) AS same_instants
            FROM duties d
            JOIN planning_periods p ON p.id = d.planning_period_id
            JOIN planning_lines l ON l.id = p.planning_line_id AND l.name = 'Fell/Ass'
            JOIN duties s ON s.id = d.coverage_source_id AND d.retired_at IS NULL
            LEFT JOIN duty_assignments ha ON ha.duty_id = d.id AND ha.current
            LEFT JOIN planning_team_members hm ON hm.id = ha.team_member_id LEFT JOIN users hu ON hu.id = hm.user_id
            LEFT JOIN duty_assignments sa ON sa.duty_id = s.id AND sa.current
            LEFT JOIN planning_team_members sm ON sm.id = sa.team_member_id LEFT JOIN users su ON su.id = sm.user_id
            ORDER BY d.local_date, d.id
            SQL));
    }

    /**
     * Every source duty of the Première ligne with its current holder.
     *
     * @return list<array{id: int, weekday: int, block: ?int, holder: ?string}>
     */
    private function sourceRows(): array
    {
        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'], 'weekday' => (int) $r['weekday'], 'block' => null !== $r['block'] ? (int) $r['block'] : null, 'holder' => $r['holder'],
        ], static::getContainer()->get(Connection::class)->fetchAllAssociative(<<<'SQL'
            SELECT d.id, EXTRACT(ISODOW FROM d.local_date) AS weekday, d.group_instance_id AS block, u.email AS holder
            FROM duties d
            JOIN planning_periods p ON p.id = d.planning_period_id
            JOIN planning_lines l ON l.id = p.planning_line_id AND l.type = 'PRIMARY'
            LEFT JOIN duty_assignments a ON a.duty_id = d.id AND a.current
            LEFT JOIN planning_team_members m ON m.id = a.team_member_id LEFT JOIN users u ON u.id = m.user_id
            ORDER BY d.local_date, d.id
            SQL));
    }

    /** ISO weekday numbers the holder of a source duty triggers. */
    private static function triggeredWeekdays(?string $holder, array $triggers = self::TRIGGERS): array
    {
        $iso = ['MONDAY' => 1, 'TUESDAY' => 2, 'WEDNESDAY' => 3, 'THURSDAY' => 4, 'FRIDAY' => 5, 'SATURDAY' => 6, 'SUNDAY' => 7];

        return array_map(static fn (string $d): int => $iso[$d], $triggers[$holder] ?? []);
    }

    /**
     * The invariants of D224 on the whole calendar, whatever the solver chose:
     * a reinforcement exactly on the triggered source days, same instants, the
     * triggered days of one source block in one block (2+) or alone (1), one
     * holder per block, never the source holder, never two current holders.
     */
    private function assertInheritedCalendarIsCoherent(array $triggers = self::TRIGGERS, bool $assigned = true): void
    {
        $fell = $this->fellRows();
        $bySource = [];
        foreach ($fell as $row) {
            self::assertArrayNotHasKey($row['source'], $bySource, 'One reinforcement per source duty, never two.');
            $bySource[$row['source']] = $row;
            self::assertTrue($row['sameInstants'], "{$row['date']}: same instants as the source duty.");
            self::assertContains($row['weekday'], self::triggeredWeekdays($row['sourceHolder'], $triggers), "{$row['date']}: a reinforcement only on a triggered day.");
            if ($assigned) {
                self::assertNotNull($row['holder'], "{$row['date']}: assigned.");
            }
            if (null !== $row['holder']) {
                self::assertNotSame($row['sourceHolder'], $row['holder'], "{$row['date']}: never the holder of the source duty.");
            }
        }
        $triggeredBySourceBlock = [];
        foreach ($this->sourceRows() as $source) {
            $triggered = \in_array($source['weekday'], self::triggeredWeekdays($source['holder'], $triggers), true);
            self::assertSame($triggered, isset($bySource[$source['id']]), "Source duty {$source['id']} ({$source['holder']}, day {$source['weekday']}): reinforced exactly when triggered.");
            if ($triggered) {
                $triggeredBySourceBlock[$source['block'] ?? 'd'.$source['id']][] = $bySource[$source['id']];
            }
        }
        foreach ($triggeredBySourceBlock as $rows) {
            $blocks = array_unique(array_map(static fn (array $r): ?int => $r['block'], $rows));
            self::assertCount(1, $blocks, 'The triggered days of one source block form one unit.');
            if (\count($rows) >= 2) {
                self::assertNotNull($rows[0]['block'], 'Two triggered days or more of one source block: one block.');
                self::assertCount(1, array_unique(array_map(static fn (array $r): ?string => $r['holder'], $rows)), 'One block, one holder — never split.');
            } else {
                self::assertNull($rows[0]['block'], 'A single triggered day: a standalone duty.');
            }
        }
        self::assertSame(0, $this->duplicateCurrentAssignments(), 'Never two current holders.');
    }
}
