<?php

declare(strict_types=1);

namespace App\Tests\Service\SurgicalHub;

use App\Service\SurgicalHub\SurgicalHubCalendar;
use PHPUnit\Framework\TestCase;

/**
 * Dates (SurgicalHub) ↔ instants (MedVue), in Europe/Brussels
 * (docs/surgicalhub-integration.md §7.1-§7.2).
 */
final class SurgicalHubCalendarTest extends TestCase
{
    private static function date(string $day): \DateTimeImmutable
    {
        return new \DateTimeImmutable($day, new \DateTimeZone('UTC'));
    }

    public function testAnAbsenceBecomesWholeLocalDaysEvenAcrossTheAutumnClockChange(): void
    {
        // 25 October 2026: clocks go back one hour in Brussels.
        [$startsAt, $endsAt] = SurgicalHubCalendar::instants(self::date('2026-10-24'), self::date('2026-10-26'));

        self::assertSame('2026-10-24T00:00:00+02:00', $startsAt->format(\DATE_ATOM));
        self::assertSame('2026-10-27T00:00:00+01:00', $endsAt->format(\DATE_ATOM));
        self::assertSame(73 * 3600, $endsAt->getTimestamp() - $startsAt->getTimestamp(), 'Three local days, one of them 25 h long.');
    }

    public function testAcrossTheSpringClockChange(): void
    {
        // 28 March 2027: clocks go forward one hour.
        [$startsAt, $endsAt] = SurgicalHubCalendar::instants(self::date('2027-03-28'), self::date('2027-03-28'));

        self::assertSame('2027-03-28T00:00:00+01:00', $startsAt->format(\DATE_ATOM));
        self::assertSame('2027-03-29T00:00:00+02:00', $endsAt->format(\DATE_ATOM));
        self::assertSame(23 * 3600, $endsAt->getTimestamp() - $startsAt->getTimestamp());
    }

    public function testTodayAndTheWindowFollowBrusselsNotUtc(): void
    {
        $calendar = new SurgicalHubCalendar(90, 24);
        // 23:30 UTC on 9 October is already 10 October in Brussels.
        $now = new \DateTimeImmutable('2026-10-09 23:30:00', new \DateTimeZone('UTC'));

        self::assertSame('2026-10-10', $calendar->today($now)->format('Y-m-d'));
        self::assertSame('2026-10-10T00:00:00+02:00', $calendar->startOfToday($now)->format(\DATE_ATOM));
        [$from, $to] = $calendar->window($now);
        self::assertSame(['2026-07-12', '2028-10-10'], [$from->format('Y-m-d'), $to->format('Y-m-d')]);
        self::assertLessThanOrEqual(850, (int) $from->diff($to)->days, 'Within SurgicalHub\'s 850-day limit.');
    }
}
