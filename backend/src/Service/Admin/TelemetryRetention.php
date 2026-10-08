<?php

declare(strict_types=1);

namespace App\Service\Admin;

use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\ClockInterface;

/**
 * Retention of the platform telemetry (docs/admin.md §5.4): activity days
 * are kept 400 days (enough for a 12-month view plus its first 30-day
 * MAU window), technical errors 90 days. The audit log is never purged.
 * Run daily by `app:platform:purge-telemetry` (cron, docs/deployment.md).
 */
final class TelemetryRetention
{
    public const ACTIVITY_RETENTION_DAYS = 400;

    public function __construct(
        private readonly Connection $connection,
        private readonly TechnicalErrorLog $errors,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @return array{activityDays: int, technicalErrors: int}
     */
    public function purge(): array
    {
        $now = $this->clock->now();
        $activityLimit = PlatformTime::dayOf($now)->modify(\sprintf('-%d days', self::ACTIVITY_RETENTION_DAYS));

        $activity = (int) $this->connection->executeStatement(
            'DELETE FROM user_activity_days WHERE activity_date < :limit',
            ['limit' => $activityLimit->format('Y-m-d')],
        );
        $errors = $this->errors->purgeOlderThan($now->modify(\sprintf('-%d days', TechnicalErrorLog::RETENTION_DAYS)));

        return ['activityDays' => $activity, 'technicalErrors' => $errors];
    }
}
