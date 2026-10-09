<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Calendar-day arithmetic of the absence export (docs/availability.md §11,
 * docs/decisions.md D181) — pure, no database, no rendering, so it is tested
 * on its own. Days are "Y-m-d" strings: a civil date, never an instant.
 *
 * A stored UserAvailabilityPeriod is a half-open interval of instants
 * [startsAt, endsAt[. The civil days it touches, in a given timezone, run
 * from the local day of startsAt to the local day of the last instant before
 * endsAt — exactly what the personal calendar shows (frontend
 * periodToRange()). A whole-day period stored as local midnight → local
 * midnight therefore covers its days and not the next one; a legacy period
 * with a time of day is widened to every day it touches.
 */
final class AbsenceDays
{
    /**
     * The first and last civil day (inclusive) that [$startsAt, $endsAt[
     * touches in $timezone.
     *
     * @return array{0: string, 1: string}
     */
    public static function touchedDays(\DateTimeImmutable $startsAt, \DateTimeImmutable $endsAt, \DateTimeZone $timezone): array
    {
        $first = $startsAt->setTimezone($timezone)->format('Y-m-d');
        $endDay = $endsAt->setTimezone($timezone)->format('Y-m-d');
        // The end is exclusive: when it falls exactly on the start of its local day (midnight, or the
        // first existing instant of a day whose midnight a DST jump skips), that day is not touched.
        $last = $endsAt <= self::startOfDay($endDay, $timezone) ? self::addDays($endDay, -1) : $endDay;

        return [$first, max($first, $last)];
    }

    /** The first instant of civil day $day in $timezone. */
    public static function startOfDay(string $day, \DateTimeZone $timezone): \DateTimeImmutable
    {
        return new \DateTimeImmutable($day.' 00:00:00', $timezone);
    }

    public static function addDays(string $day, int $days): string
    {
        return (new \DateTimeImmutable($day.' 12:00:00', new \DateTimeZone('UTC')))->modify(\sprintf('%+d days', $days))->format('Y-m-d');
    }

    /**
     * Every day of [$first, $last] (inclusive), in order.
     *
     * @return list<string>
     */
    public static function each(string $first, string $last): array
    {
        $days = [];
        for ($day = $first; $day <= $last; $day = self::addDays($day, 1)) {
            $days[] = $day;
        }

        return $days;
    }

    /**
     * The intersection of [$first, $last] with [$min, $max] (all inclusive),
     * or null when they do not meet.
     *
     * @return array{0: string, 1: string}|null
     */
    public static function clip(string $first, string $last, string $min, string $max): ?array
    {
        $from = max($first, $min);
        $to = min($last, $max);

        return $from <= $to ? [$from, $to] : null;
    }

    /**
     * Consecutive days grouped into runs — presentation only: the stored
     * periods are never merged.
     *
     * @param list<string> $days sorted, distinct
     *
     * @return list<array{0: string, 1: string}> [first, last] of each run
     */
    public static function runs(array $days): array
    {
        $runs = [];
        foreach ($days as $day) {
            $count = \count($runs);
            if ($count > 0 && self::addDays($runs[$count - 1][1], 1) === $day) {
                $runs[$count - 1][1] = $day;
            } else {
                $runs[] = [$day, $day];
            }
        }

        return $runs;
    }
}
