<?php

declare(strict_types=1);

namespace App\Service;

/**
 * The calendar of a period's preparation (docs/decisions.md D210): every
 * announcement and reminder runs at 08:00 Europe/Brussels on its day, and
 * "today" is the Brussels day — whatever the server's own time zone.
 * Dates are whole days, represented at midnight UTC like every other `date`
 * column of the application.
 */
final class PreparationCalendar
{
    public const TIMEZONE = 'Europe/Brussels';
    public const SEND_TIME = '08:00';

    /** The UTC instant a planned day runs at. */
    public static function runAt(\DateTimeImmutable $day): \DateTimeImmutable
    {
        return (new \DateTimeImmutable($day->format('Y-m-d').' '.self::SEND_TIME, new \DateTimeZone(self::TIMEZONE)))
            ->setTimezone(new \DateTimeZone('UTC'));
    }

    /** The Brussels day of $now, as a date. */
    public static function today(\DateTimeImmutable $now): \DateTimeImmutable
    {
        return self::day($now->setTimezone(new \DateTimeZone(self::TIMEZONE))->format('Y-m-d'));
    }

    public static function day(string $ymd): \DateTimeImmutable
    {
        return new \DateTimeImmutable($ymd.' 00:00:00', new \DateTimeZone('UTC'));
    }

    public static function ymd(?\DateTimeImmutable $day): ?string
    {
        return $day?->format('Y-m-d');
    }
}
