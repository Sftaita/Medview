<?php

declare(strict_types=1);

namespace App\Service\Admin;

use App\Entity\User;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * Activity instrumentation (docs/admin.md §5, docs/decisions.md D175): one
 * row per user and per active day, written when a session is opened (login)
 * or renewed (refresh-token rotation) — never on every request.
 *
 * Why those two moments are enough: the access token lives 15 minutes and
 * the frontend always renews the session when the app is opened, so any use
 * of MedVue on a given day goes through one of them. What is NOT captured:
 * a session started before midnight and used for less than 15 minutes after
 * it counts for the earlier day only.
 *
 * Only the user and the date (plus first/last time of that day) are kept —
 * no IP, no page, no user agent. Rows older than the retention period are
 * deleted by `app:platform:purge-telemetry`.
 *
 * Best-effort by design: a failure here is logged and never breaks a login
 * or a refresh.
 */
final class UserActivityRecorder
{
    public function __construct(
        private readonly Connection $connection,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function record(User $user): void
    {
        $userId = $user->getId();
        if (null === $userId) {
            return;
        }

        $now = $this->clock->now();
        try {
            $this->connection->executeStatement(
                <<<'SQL'
                    INSERT INTO user_activity_days (user_id, activity_date, first_seen_at, last_seen_at)
                    VALUES (:user, :day, :now, :now)
                    ON CONFLICT (user_id, activity_date)
                    DO UPDATE SET last_seen_at = GREATEST(user_activity_days.last_seen_at, EXCLUDED.last_seen_at)
                SQL,
                [
                    'user' => $userId,
                    'day' => PlatformTime::dayOf($now)->format('Y-m-d'),
                    'now' => PlatformTime::utc($now),
                ],
            );
        } catch (\Throwable $exception) {
            $this->logger->warning('Could not record user activity.', ['exception' => $exception]);
        }
    }
}
