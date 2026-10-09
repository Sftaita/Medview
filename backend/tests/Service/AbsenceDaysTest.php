<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\AbsenceDays;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The civil-day arithmetic of the absence export (docs/decisions.md D181),
 * independent from any rendering: half-open instants → inclusive civil days
 * in the planning's timezone.
 */
final class AbsenceDaysTest extends TestCase
{
    /**
     * @return iterable<string, array{0: string, 1: string, 2: string, 3: string, 4: string}>
     */
    public static function periods(): iterable
    {
        $tz = 'Europe/Brussels';
        yield 'whole days, local midnight to local midnight: the end day is excluded' => ['2027-01-15T00:00:00+01:00', '2027-01-19T00:00:00+01:00', $tz, '2027-01-15', '2027-01-18'];
        yield 'one whole day' => ['2027-02-03T00:00:00+01:00', '2027-02-04T00:00:00+01:00', $tz, '2027-02-03', '2027-02-03'];
        yield 'a time of day on both ends: every day touched counts' => ['2027-01-15T14:30:00+01:00', '2027-01-17T09:00:00+01:00', $tz, '2027-01-15', '2027-01-17'];
        yield 'a few hours inside one day' => ['2027-01-15T08:00:00+01:00', '2027-01-15T12:00:00+01:00', $tz, '2027-01-15', '2027-01-15'];
        yield 'ends one minute after midnight: the next day is touched' => ['2027-01-15T22:00:00+01:00', '2027-01-16T00:01:00+01:00', $tz, '2027-01-15', '2027-01-16'];
        yield 'stored in UTC, read in the planning timezone' => ['2027-01-14T23:00:00+00:00', '2027-01-16T23:00:00+00:00', $tz, '2027-01-15', '2027-01-16'];
        yield 'spring DST (23 h day): midnight to midnight across 28 March 2027' => ['2027-03-27T00:00:00+01:00', '2027-03-29T00:00:00+02:00', $tz, '2027-03-27', '2027-03-28'];
        yield 'autumn DST (25 h day): midnight to midnight across 31 October 2027' => ['2027-10-30T00:00:00+02:00', '2027-11-01T00:00:00+01:00', $tz, '2027-10-30', '2027-10-31'];
        yield 'DST day entered as a fixed 24 h from midnight: ends at 01:00 the next day, which is touched' => ['2027-03-28T00:00:00+01:00', '2027-03-29T01:00:00+02:00', $tz, '2027-03-28', '2027-03-29'];
        yield 'another timezone: the same instants fall on other days' => ['2027-01-15T00:00:00+01:00', '2027-01-16T00:00:00+01:00', 'America/New_York', '2027-01-14', '2027-01-15'];
        // Santiago skips 00:00 → 01:00 on 5 September 2027: the day starts at 01:00.
        yield 'a day whose midnight does not exist starts at its first instant' => ['2027-09-04T00:00:00-04:00', '2027-09-05T01:00:00-03:00', 'America/Santiago', '2027-09-04', '2027-09-04'];
    }

    #[DataProvider('periods')]
    public function testTouchedDays(string $startsAt, string $endsAt, string $timezone, string $first, string $last): void
    {
        self::assertSame([$first, $last], AbsenceDays::touchedDays(new \DateTimeImmutable($startsAt), new \DateTimeImmutable($endsAt), new \DateTimeZone($timezone)));
    }

    public function testClipKeepsOnlyTheDaysInsideTheBounds(): void
    {
        self::assertSame(['2027-01-15', '2027-01-18'], AbsenceDays::clip('2027-01-10', '2027-01-18', '2027-01-15', '2027-03-14'), 'Starts before.');
        self::assertSame(['2027-03-10', '2027-03-14'], AbsenceDays::clip('2027-03-10', '2027-03-20', '2027-01-15', '2027-03-14'), 'Ends after.');
        self::assertSame(['2027-01-15', '2027-03-14'], AbsenceDays::clip('2026-12-01', '2027-05-01', '2027-01-15', '2027-03-14'), 'Covers the whole period.');
        self::assertNull(AbsenceDays::clip('2027-01-01', '2027-01-14', '2027-01-15', '2027-03-14'), 'Ends the day before.');
        self::assertNull(AbsenceDays::clip('2027-03-15', '2027-03-20', '2027-01-15', '2027-03-14'), 'Starts the day after the last day.');
        self::assertSame(['2027-01-15', '2027-01-15'], AbsenceDays::clip('2027-01-10', '2027-01-15', '2027-01-15', '2027-03-14'), 'Touches the first day only.');
    }

    public function testEachAndAddDaysCrossMonthsYearsAndDstWithoutDrift(): void
    {
        self::assertSame(['2026-12-30', '2026-12-31', '2027-01-01', '2027-01-02'], AbsenceDays::each('2026-12-30', '2027-01-02'));
        self::assertSame(['2027-03-27', '2027-03-28', '2027-03-29'], AbsenceDays::each('2027-03-27', '2027-03-29'));
        self::assertSame([], AbsenceDays::each('2027-01-02', '2027-01-01'));
        self::assertSame('2028-02-29', AbsenceDays::addDays('2028-03-01', -1), 'Leap year.');
        self::assertCount(365, AbsenceDays::each('2027-01-01', '2027-12-31'));
    }

    public function testRunsGroupConsecutiveDaysOnly(): void
    {
        self::assertSame([], AbsenceDays::runs([]));
        self::assertSame(
            [['2027-01-15', '2027-01-18'], ['2027-02-03', '2027-02-03'], ['2027-12-31', '2028-01-02']],
            AbsenceDays::runs(['2027-01-15', '2027-01-16', '2027-01-17', '2027-01-18', '2027-02-03', '2027-12-31', '2028-01-01', '2028-01-02']),
        );
    }

    public function testStartOfDayIsLocalMidnight(): void
    {
        self::assertSame('2027-03-28T00:00:00+01:00', AbsenceDays::startOfDay('2027-03-28', new \DateTimeZone('Europe/Brussels'))->format(\DATE_ATOM));
        self::assertSame('2027-03-29T00:00:00+02:00', AbsenceDays::startOfDay('2027-03-29', new \DateTimeZone('Europe/Brussels'))->format(\DATE_ATOM));
    }
}
