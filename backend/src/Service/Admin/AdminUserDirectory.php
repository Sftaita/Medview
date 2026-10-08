<?php

declare(strict_types=1);

namespace App\Service\Admin;

use App\Entity\User;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\Clock\ClockInterface;

/**
 * Read side of the "Utilisateurs" page (docs/admin.md §4): account facts
 * only, aggregated in SQL — one query for a page of users and one for its
 * total, never one query per row.
 *
 * Never selected here: password hash, credentials version, raw or hashed
 * tokens, IP addresses.
 *
 * "Plannings" of a user = distinct plannings in which they hold a team
 * membership that has not ended (membership_end empty or in the future):
 * account context, not a door into those plannings.
 */
final class AdminUserDirectory
{
    public const SORTS = ['createdAt', 'lastActivity', 'name', 'email'];
    public const STATUSES = ['all', 'active', 'disabled'];

    private const ORDER_BY = [
        'createdAt' => ['u.created_at'],
        'lastActivity' => ['a.last_seen_at'],
        'name' => ['LOWER(u.last_name)', 'LOWER(u.first_name)'],
        'email' => ['LOWER(u.email)'],
    ];

    public function __construct(
        private readonly Connection $connection,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function search(string $search, string $status, string $sort, string $direction, int $page, int $perPage): array
    {
        if (!\in_array($status, self::STATUSES, true) || !\array_key_exists($sort, self::ORDER_BY) || !\in_array($direction, ['asc', 'desc'], true)) {
            throw new \InvalidArgumentException('Unsupported filter or sort.');
        }

        [$where, $params] = $this->where($search, $status);
        $today = PlatformTime::dayOf($this->clock->now())->format('Y-m-d');

        $order = implode(', ', array_map(
            static fn (string $column): string => \sprintf('%s %s NULLS LAST', $column, strtoupper($direction)),
            self::ORDER_BY[$sort],
        ));

        $rows = $this->connection->fetchAllAssociative(
            <<<SQL
                SELECT u.stable_id, u.email, u.first_name, u.last_name, u.phone_e164, u.active, u.platform_admin, u.created_at,
                       a.last_seen_at, COALESCE(p.planning_count, 0) AS planning_count
                FROM users u
                LEFT JOIN (SELECT user_id, MAX(last_seen_at) AS last_seen_at FROM user_activity_days GROUP BY user_id) a ON a.user_id = u.id
                LEFT JOIN (
                    SELECT user_id, COUNT(DISTINCT planning_id) AS planning_count
                    FROM planning_team_members
                    WHERE membership_end IS NULL OR membership_end > :today
                    GROUP BY user_id
                ) p ON p.user_id = u.id
                WHERE {$where}
                ORDER BY {$order}, u.id ASC
                LIMIT :limit OFFSET :offset
            SQL,
            [...$params, 'today' => $today, 'limit' => $perPage, 'offset' => ($page - 1) * $perPage],
            ['limit' => ParameterType::INTEGER, 'offset' => ParameterType::INTEGER],
        );

        $total = (int) $this->connection->fetchOne("SELECT COUNT(*) FROM users u WHERE {$where}", $params);

        return [
            'items' => array_map(static fn (array $row): array => [
                'stableId' => (string) $row['stable_id'],
                'email' => (string) $row['email'],
                'firstName' => (string) $row['first_name'],
                'lastName' => (string) $row['last_name'],
                'phone' => null !== $row['phone_e164'] ? (string) $row['phone_e164'] : null,
                'active' => (bool) $row['active'],
                'platformAdmin' => (bool) $row['platform_admin'],
                'createdAt' => AdminFormat::utcToIso((string) $row['created_at']),
                'lastActivityAt' => AdminFormat::utcToIso(null !== $row['last_seen_at'] ? (string) $row['last_seen_at'] : null),
                'planningCount' => (int) $row['planning_count'],
            ], $rows),
            'total' => $total,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function detail(User $user): array
    {
        $userId = $user->getId();
        $now = $this->clock->now();
        $today = PlatformTime::dayOf($now);

        $activity = $this->connection->fetchAssociative(
            <<<'SQL'
                SELECT MAX(last_seen_at) AS last_seen_at,
                       COUNT(*) FILTER (WHERE activity_date > :from30) AS days30,
                       MIN(activity_date) AS first_day
                FROM user_activity_days WHERE user_id = :user
            SQL,
            ['user' => $userId, 'from30' => $today->modify('-30 days')->format('Y-m-d')],
        ) ?: [];

        $sessions = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT MIN(created_at) AS started_at,
                       MAX(created_at) AS last_used_at,
                       MAX(expires_at) AS expires_at,
                       BOOL_OR(revoked_at IS NULL AND expires_at > :now) AS active,
                       (ARRAY_AGG(user_agent ORDER BY created_at DESC))[1] AS user_agent
                FROM refresh_tokens
                WHERE user_id = :user
                GROUP BY family_id
                ORDER BY MAX(created_at) DESC
                LIMIT 20
            SQL,
            ['user' => $userId, 'now' => PlatformTime::utc($now)],
        );

        $activeSessions = (int) $this->connection->fetchOne(
            'SELECT COUNT(DISTINCT family_id) FROM refresh_tokens WHERE user_id = :user AND revoked_at IS NULL AND expires_at > :now',
            ['user' => $userId, 'now' => PlatformTime::utc($now)],
        );

        $plannings = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT p.stable_id, p.name, p.created_at, (p.creator_id = :user) AS creator,
                       ARRAY_TO_STRING(ARRAY_AGG(DISTINCT m.role) FILTER (WHERE m.id IS NOT NULL), ',') AS roles,
                       BOOL_OR(m.id IS NOT NULL AND (m.membership_end IS NULL OR m.membership_end > :today)) AS ongoing
                FROM plannings p
                LEFT JOIN planning_team_members m ON m.planning_id = p.id AND m.user_id = :user
                WHERE p.creator_id = :user OR m.id IS NOT NULL
                GROUP BY p.id
                ORDER BY p.created_at DESC
                LIMIT 50
            SQL,
            ['user' => $userId, 'today' => $today->format('Y-m-d')],
        );

        return [
            'stableId' => (string) $user->getStableId(),
            'email' => $user->getEmail(),
            'firstName' => $user->getFirstName(),
            'lastName' => $user->getLastName(),
            'phone' => $user->getPhoneE164(),
            'active' => $user->isActive(),
            'platformAdmin' => $user->isPlatformAdmin(),
            'emailVerified' => null !== $user->getEmailVerifiedAt(),
            'createdAt' => AdminFormat::iso($user->getCreatedAt()),
            'updatedAt' => AdminFormat::iso($user->getUpdatedAt()),
            'activity' => [
                'lastActivityAt' => AdminFormat::utcToIso(isset($activity['last_seen_at']) ? (string) $activity['last_seen_at'] : null),
                'activeDaysLast30' => (int) ($activity['days30'] ?? 0),
                'firstActivityDay' => isset($activity['first_day']) ? (string) $activity['first_day'] : null,
            ],
            'activeSessionCount' => $activeSessions,
            'sessions' => array_map(static fn (array $row): array => [
                'startedAt' => AdminFormat::utcToIso((string) $row['started_at']),
                'lastUsedAt' => AdminFormat::utcToIso((string) $row['last_used_at']),
                'expiresAt' => AdminFormat::utcToIso((string) $row['expires_at']),
                'active' => (bool) $row['active'],
                'device' => UserAgentSummary::describe(null !== $row['user_agent'] ? (string) $row['user_agent'] : null),
            ], $sessions),
            'plannings' => array_map(static fn (array $row): array => [
                'stableId' => (string) $row['stable_id'],
                'name' => (string) $row['name'],
                'createdAt' => AdminFormat::utcToIso((string) $row['created_at']),
                'creator' => (bool) $row['creator'],
                'roles' => '' === (string) $row['roles'] ? [] : explode(',', (string) $row['roles']),
                'ongoing' => (bool) $row['ongoing'],
            ], $plannings),
        ];
    }

    /**
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function where(string $search, string $status): array
    {
        $conditions = ['TRUE'];
        $params = [];

        $search = trim(mb_strtolower($search));
        if ('' !== $search) {
            $like = '%'.addcslashes($search, '%_\\').'%';
            $conditions[] = "(LOWER(u.email) LIKE :like OR LOWER(u.first_name || ' ' || u.last_name) LIKE :like OR LOWER(u.last_name || ' ' || u.first_name) LIKE :like)";
            $params['like'] = $like;
        }

        if ('active' === $status) {
            $conditions[] = 'u.active = TRUE';
        } elseif ('disabled' === $status) {
            $conditions[] = 'u.active = FALSE';
        }

        return [implode(' AND ', $conditions), $params];
    }

    /**
     * @param list<int> $userIds
     *
     * @return array<int, string> user id => ISO last activity
     */
    public function lastActivityOf(array $userIds): array
    {
        if ([] === $userIds) {
            return [];
        }

        $rows = $this->connection->fetchAllKeyValue(
            'SELECT user_id, MAX(last_seen_at) FROM user_activity_days WHERE user_id IN (:ids) GROUP BY user_id',
            ['ids' => $userIds],
            ['ids' => ArrayParameterType::INTEGER],
        );

        return array_map(static fn (mixed $value): string => (string) AdminFormat::utcToIso((string) $value), $rows);
    }
}
