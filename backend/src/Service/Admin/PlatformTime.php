<?php

declare(strict_types=1);

namespace App\Service\Admin;

/**
 * The calendar the platform statistics are counted in (docs/admin.md §5).
 * Timestamps are stored in UTC everywhere; a "day" of activity, a
 * registration "per day" or "per month" is a day of this time zone — the
 * one MedVue's users live in. The migration that backfilled
 * user_activity_days (Version20261008100000) uses the same zone literally:
 * changing it here alone would split one day in two for history.
 */
final class PlatformTime
{
    public const TIMEZONE = 'Europe/Brussels';

    public static function zone(): \DateTimeZone
    {
        return new \DateTimeZone(self::TIMEZONE);
    }

    /** The platform-calendar date of an instant, at midnight in the platform zone. */
    public static function dayOf(\DateTimeImmutable $instant): \DateTimeImmutable
    {
        return $instant->setTimezone(self::zone())->setTime(0, 0);
    }

    /** "Y-m-d H:i:s" in UTC, the storage form of every TIMESTAMP column. */
    public static function utc(\DateTimeImmutable $instant): string
    {
        return $instant->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
