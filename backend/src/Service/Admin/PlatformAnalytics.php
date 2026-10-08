<?php

declare(strict_types=1);

namespace App\Service\Admin;

use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\ClockInterface;

/**
 * Adoption and growth figures of the platform (docs/admin.md §5,
 * docs/decisions.md D175). Every figure comes from one of three sources,
 * named next to it in the documentation:
 *
 *  - users.created_at / users.active        → accounts, registrations;
 *  - plannings.created_at                   → plannings created (an adoption
 *                                             signal only, never their content);
 *  - user_activity_days                     → "active users" (opened or
 *                                             renewed a session that day).
 *
 * Days are days of PlatformTime::TIMEZONE; "the last N days" always means
 * today and the N-1 days before it. Every query is an aggregate over
 * indexed columns (users.created_at, plannings.created_at,
 * user_activity_days.activity_date) — no per-user loop.
 */
final class PlatformAnalytics
{
    /** range => [default granularity, number of days or months] */
    public const RANGES = [
        '7d' => ['day', 7],
        '30d' => ['day', 30],
        '90d' => ['week', 90],
        '12m' => ['month', 12],
    ];

    public const GRANULARITIES = ['day', 'week', 'month'];

    private const TZ = PlatformTime::TIMEZONE;

    public function __construct(
        private readonly Connection $connection,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function overview(): array
    {
        $today = $this->today();
        $from30 = $today->modify('-29 days');

        $users = $this->connection->fetchAssociative(
            <<<'SQL'
                SELECT COUNT(*) AS total,
                       COUNT(*) FILTER (WHERE active) AS active,
                       COUNT(*) FILTER (WHERE NOT active) AS disabled,
                       COUNT(*) FILTER (WHERE created_at >= :since) AS recent,
                       COUNT(*) FILTER (WHERE platform_admin) AS admins
                FROM users
            SQL,
            ['since' => PlatformTime::utc($from30)],
        ) ?: [];

        $plannings = $this->connection->fetchAssociative(
            'SELECT COUNT(*) AS total, COUNT(*) FILTER (WHERE created_at >= :since) AS recent FROM plannings',
            ['since' => PlatformTime::utc($from30)],
        ) ?: [];

        $active = $this->connection->fetchAssociative(
            <<<'SQL'
                SELECT COUNT(DISTINCT user_id) FILTER (WHERE activity_date >= :from7) AS active7,
                       COUNT(DISTINCT user_id) AS active30
                FROM user_activity_days
                WHERE activity_date >= :from30 AND activity_date <= :today
            SQL,
            [
                'from7' => $today->modify('-6 days')->format('Y-m-d'),
                'from30' => $from30->format('Y-m-d'),
                'today' => $today->format('Y-m-d'),
            ],
        ) ?: [];

        return [
            'generatedAt' => AdminFormat::iso($this->clock->now()),
            'timezone' => self::TZ,
            'users' => [
                'total' => (int) ($users['total'] ?? 0),
                'activeAccounts' => (int) ($users['active'] ?? 0),
                'disabledAccounts' => (int) ($users['disabled'] ?? 0),
                'registeredLast30Days' => (int) ($users['recent'] ?? 0),
                'platformAdmins' => (int) ($users['admins'] ?? 0),
            ],
            'plannings' => [
                'total' => (int) ($plannings['total'] ?? 0),
                'createdLast30Days' => (int) ($plannings['recent'] ?? 0),
            ],
            'activity' => [
                'activeUsersLast7Days' => (int) ($active['active7'] ?? 0),
                'activeUsersLast30Days' => (int) ($active['active30'] ?? 0),
                'dataSince' => $this->activityDataSince(),
            ],
        ];
    }

    /**
     * One point per bucket of the range, zero-filled: registrations,
     * plannings created, distinct active users and active user-days.
     *
     * @return array<string, mixed>
     */
    public function timeseries(string $range, ?string $granularity = null): array
    {
        [$unit, $from, $to] = $this->window($range, $granularity);
        $fromUtc = PlatformTime::utc($from);
        $toUtc = PlatformTime::utc($to->modify('+1 day'));

        $registrations = $this->countByBucket('users', $unit, $fromUtc, $toUtc);
        $plannings = $this->countByBucket('plannings', $unit, $fromUtc, $toUtc);
        $activity = $this->connection->fetchAllAssociativeIndexed(
            <<<SQL
                SELECT DATE_TRUNC('{$unit}', activity_date)::date AS bucket,
                       COUNT(DISTINCT user_id) AS users,
                       COUNT(*) AS days
                FROM user_activity_days
                WHERE activity_date >= :from AND activity_date <= :to
                GROUP BY 1
            SQL,
            ['from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d')],
        );

        $points = [];
        foreach ($this->buckets($unit, $from, $to) as $bucket) {
            $key = $bucket->format('Y-m-d');
            $points[] = [
                'bucket' => $key,
                'registrations' => (int) ($registrations[$key] ?? 0),
                'plannings' => (int) ($plannings[$key] ?? 0),
                'activeUsers' => (int) ($activity[$key]['users'] ?? 0),
                'activeUserDays' => (int) ($activity[$key]['days'] ?? 0),
            ];
        }

        return [
            'range' => $range,
            'granularity' => $unit,
            'from' => $from->format('Y-m-d'),
            'to' => $to->format('Y-m-d'),
            'timezone' => self::TZ,
            'points' => $points,
            'totals' => [
                'registrations' => array_sum(array_column($points, 'registrations')),
                'plannings' => array_sum(array_column($points, 'plannings')),
                // Distinct over the whole window, not a sum of the buckets.
                'activeUsers' => (int) $this->connection->fetchOne(
                    'SELECT COUNT(DISTINCT user_id) FROM user_activity_days WHERE activity_date >= :from AND activity_date <= :to',
                    ['from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d')],
                ),
            ],
        ];
    }

    /**
     * DAU/MAU, return rates and account seniority (docs/admin.md §5.3).
     *
     * @return array<string, mixed>
     */
    public function adoption(string $range): array
    {
        if (!\array_key_exists($range, self::RANGES) || '7d' === $range) {
            throw new \InvalidArgumentException('Unsupported range.');
        }

        $today = $this->today();
        $from = '12m' === $range ? $today->modify('-364 days') : $today->modify(\sprintf('-%d days', self::RANGES[$range][1] - 1));

        $series = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT d::date AS day,
                       (SELECT COUNT(*) FROM user_activity_days a WHERE a.activity_date = d::date) AS dau,
                       (SELECT COUNT(DISTINCT a.user_id) FROM user_activity_days a
                         WHERE a.activity_date BETWEEN d::date - 29 AND d::date) AS mau
                FROM GENERATE_SERIES(CAST(:from AS date), CAST(:to AS date), INTERVAL '1 day') AS d
                ORDER BY d
            SQL,
            ['from' => $from->format('Y-m-d'), 'to' => $today->format('Y-m-d')],
        );

        $last30 = \array_slice($series, -30);
        $averageDau = [] === $last30 ? 0.0 : array_sum(array_map(static fn (array $row): int => (int) $row['dau'], $last30)) / \count($last30);
        $mau = (int) (end($series)['mau'] ?? 0);

        return [
            'range' => $range,
            'timezone' => self::TZ,
            'dataSince' => $this->activityDataSince(),
            'dauMau' => array_map(static fn (array $row): array => [
                'day' => (string) $row['day'],
                'dau' => (int) $row['dau'],
                'mau' => (int) $row['mau'],
            ], $series),
            'current' => [
                'dau' => (int) (end($series)['dau'] ?? 0),
                'mau' => $mau,
                'averageDauLast30Days' => round($averageDau, 1),
                // null rather than 0 % when nobody was active: no ratio to speak of.
                'stickiness' => $mau > 0 ? round($averageDau / $mau, 3) : null,
            ],
            'retention' => [
                'day7' => $this->retention(7),
                'day30' => $this->retention(30),
            ],
            'seniority' => $this->seniority(),
        ];
    }

    /**
     * Share of the people who registered over 90 consecutive days — the most
     * recent 90 whose window is complete — who used MedVue again on a later
     * day within $window days of their registration day. The registration day
     * itself does not count (registering logs you in).
     *
     * @return array{windowDays: int, cohortFrom: string, cohortTo: string, cohortSize: int, returned: int, rate: float|null}
     */
    private function retention(int $window): array
    {
        $today = $this->today();
        $cohortTo = $today->modify(\sprintf('-%d days', $window));
        $cohortFrom = $cohortTo->modify('-89 days');

        $row = $this->connection->fetchAssociative(
            <<<SQL
                SELECT COUNT(*) AS cohort,
                       COUNT(*) FILTER (WHERE EXISTS (
                           SELECT 1 FROM user_activity_days a
                           WHERE a.user_id = s.id AND a.activity_date > s.day AND a.activity_date <= s.day + {$window}
                       )) AS returned
                FROM (
                    SELECT id, ((created_at AT TIME ZONE 'UTC') AT TIME ZONE '{$this->tz()}')::date AS day FROM users
                ) s
                WHERE s.day >= :from AND s.day <= :to
            SQL,
            ['from' => $cohortFrom->format('Y-m-d'), 'to' => $cohortTo->format('Y-m-d')],
        ) ?: [];

        $cohort = (int) ($row['cohort'] ?? 0);
        $returned = (int) ($row['returned'] ?? 0);

        return [
            'windowDays' => $window,
            'cohortFrom' => $cohortFrom->format('Y-m-d'),
            'cohortTo' => $cohortTo->format('Y-m-d'),
            'cohortSize' => $cohort,
            'returned' => $returned,
            'rate' => $cohort > 0 ? round($returned / $cohort, 3) : null,
        ];
    }

    /**
     * Active (not deactivated) accounts by age of the account.
     *
     * @return list<array{bucket: string, count: int}>
     */
    private function seniority(): array
    {
        $now = $this->clock->now();
        $row = $this->connection->fetchAssociative(
            <<<'SQL'
                SELECT COUNT(*) FILTER (WHERE created_at > :d30) AS lt30,
                       COUNT(*) FILTER (WHERE created_at <= :d30 AND created_at > :d90) AS lt90,
                       COUNT(*) FILTER (WHERE created_at <= :d90 AND created_at > :d365) AS lt365,
                       COUNT(*) FILTER (WHERE created_at <= :d365) AS gte365
                FROM users WHERE active
            SQL,
            [
                'd30' => PlatformTime::utc($now->modify('-30 days')),
                'd90' => PlatformTime::utc($now->modify('-90 days')),
                'd365' => PlatformTime::utc($now->modify('-365 days')),
            ],
        ) ?: [];

        return [
            ['bucket' => 'lt30', 'count' => (int) ($row['lt30'] ?? 0)],
            ['bucket' => '30to89', 'count' => (int) ($row['lt90'] ?? 0)],
            ['bucket' => '90to364', 'count' => (int) ($row['lt365'] ?? 0)],
            ['bucket' => 'gte365', 'count' => (int) ($row['gte365'] ?? 0)],
        ];
    }

    /**
     * @return array{0: string, 1: \DateTimeImmutable, 2: \DateTimeImmutable} unit, first bucket start, last day (inclusive)
     */
    private function window(string $range, ?string $granularity): array
    {
        if (!\array_key_exists($range, self::RANGES)) {
            throw new \InvalidArgumentException('Unsupported range.');
        }
        if (null !== $granularity && !\in_array($granularity, self::GRANULARITIES, true)) {
            throw new \InvalidArgumentException('Unsupported granularity.');
        }

        [$defaultUnit, $length] = self::RANGES[$range];
        $unit = $granularity ?? $defaultUnit;
        $today = $this->today();
        $from = '12m' === $range
            ? $today->modify('first day of this month')->modify(\sprintf('-%d months', $length - 1))
            : $today->modify(\sprintf('-%d days', $length - 1));

        return [$unit, $this->bucketStart($unit, $from), $today];
    }

    private function bucketStart(string $unit, \DateTimeImmutable $day): \DateTimeImmutable
    {
        return match ($unit) {
            'week' => $day->modify('-'.((int) $day->format('N') - 1).' days'),
            'month' => $day->modify('first day of this month'),
            default => $day,
        };
    }

    /**
     * @return list<\DateTimeImmutable>
     */
    private function buckets(string $unit, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $step = match ($unit) {
            'week' => '+1 week',
            'month' => '+1 month',
            default => '+1 day',
        };

        $buckets = [];
        for ($cursor = $from; $cursor <= $to; $cursor = $cursor->modify($step)) {
            $buckets[] = $cursor;
        }

        return $buckets;
    }

    /**
     * @return array<string, int> bucket ("Y-m-d") => count
     */
    private function countByBucket(string $table, string $unit, string $fromUtc, string $toUtc): array
    {
        // $table and $unit come from code constants only, never from the request.
        $rows = $this->connection->fetchAllKeyValue(
            <<<SQL
                SELECT DATE_TRUNC('{$unit}', ((created_at AT TIME ZONE 'UTC') AT TIME ZONE '{$this->tz()}'))::date AS bucket, COUNT(*)
                FROM {$table}
                WHERE created_at >= :from AND created_at < :to
                GROUP BY 1
            SQL,
            ['from' => $fromUtc, 'to' => $toUtc],
        );

        return array_map('intval', $rows);
    }

    private function activityDataSince(): ?string
    {
        $first = $this->connection->fetchOne('SELECT MIN(activity_date) FROM user_activity_days');

        return false === $first || null === $first ? null : (string) $first;
    }

    /** Today in the platform zone, at midnight of that zone. */
    private function today(): \DateTimeImmutable
    {
        return PlatformTime::dayOf($this->clock->now());
    }

    private function tz(): string
    {
        return self::TZ;
    }
}
