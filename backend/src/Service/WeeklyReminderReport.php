<?php

declare(strict_types=1);

namespace App\Service;

/**
 * What one weekly reminder run did (docs/decisions.md D146) — printed by
 * the console command for the cron log.
 */
final readonly class WeeklyReminderReport
{
    public function __construct(
        public int $planningCount,
        public int $sentCount,
        public int $alreadySentCount,
        public int $failedCount,
    ) {
    }
}
