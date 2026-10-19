<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\BlockCut;
use App\Service\PeriodBoundaryGuard;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The rule of PeriodBoundaryGuard::cutsAt() (docs/planning-cycles.md §5.1,
 * lot L3): an occurrence of a block — its components' days, from each
 * week's Monday, exactly as WeeklyDutyCalendarService materializes them —
 * is cut by a boundary when its first day is before it and its last day on
 * or after it. Whatever the length of the block, contiguous or not, even
 * reaching into the next week.
 */
final class PeriodBoundaryGuardTest extends KernelTestCase
{
    private function guard(): PeriodBoundaryGuard
    {
        return static::getContainer()->get(PeriodBoundaryGuard::class);
    }

    /**
     * @param list<int> $offsets
     *
     * @return list<array{0: string, 1: string}> [first day, last day] of each occurrence cut
     */
    private function cuts(array $offsets, string $boundary): array
    {
        return array_map(
            static fn (BlockCut $cut): array => [$cut->firstDay->format('Y-m-d'), $cut->lastDay->format('Y-m-d')],
            $this->guard()->cutsAt([['name' => 'B', 'offsets' => $offsets]], new \DateTimeImmutable($boundary)),
        );
    }

    public function testAFridayToSundayWeekend(): void
    {
        // 2027-05-01 is a Saturday, 2027-05-02 a Sunday, 2027-05-03 a Monday.
        self::assertSame([['2027-04-30', '2027-05-02']], $this->cuts([4, 5, 6], '2027-05-01'));
        self::assertSame([['2027-04-30', '2027-05-02']], $this->cuts([4, 5, 6], '2027-05-02'));
        self::assertSame([], $this->cuts([4, 5, 6], '2027-04-30'), 'a boundary on its first day: the whole block is after it');
        self::assertSame([], $this->cuts([4, 5, 6], '2027-05-03'), 'a boundary on the Monday: the whole block is before it');
    }

    public function testATwoDayBlockAndANonContiguousOne(): void
    {
        self::assertSame([['2027-04-30', '2027-05-01']], $this->cuts([4, 5], '2027-05-01'));
        self::assertSame([], $this->cuts([4, 5], '2027-05-02'));
        // Monday and Wednesday of one week, materialized together: a Tuesday boundary separates them.
        self::assertSame([['2027-04-26', '2027-04-28']], $this->cuts([0, 2], '2027-04-27'));
    }

    public function testABlockReachingIntoTheNextWeek(): void
    {
        // Saturday → Tuesday (offsets 5..8): the occurrence of the week before is the one a Monday boundary cuts.
        self::assertSame([['2027-05-01', '2027-05-04']], $this->cuts([5, 6, 7, 8], '2027-05-03'));
        self::assertSame([], $this->cuts([5, 6, 7, 8], '2027-05-01'));
    }

    public function testACutSaysWhichDaysAreBeforeAndWhichRemain(): void
    {
        // docs/decisions.md D192 — the production case: boundary Sunday 31 January 2027, block Friday → Sunday.
        $cut = $this->guard()->cutsAt([['name' => 'B', 'offsets' => [4, 5, 6]]], new \DateTimeImmutable('2027-01-31'))[0];
        $format = static fn (array $days): array => array_map(static fn (\DateTimeImmutable $d): string => $d->format('Y-m-d'), $days);

        self::assertSame(['2027-01-29', '2027-01-30'], $format($cut->earlierDays()));
        self::assertSame(['2027-01-31'], $format($cut->remainderDays()));
        self::assertSame(['2027-01-29', '2027-01-30', '2027-01-31'], $format($cut->days));
    }

    public function testASingleDayIsNeverCut(): void
    {
        self::assertSame([], $this->cuts([5], '2027-05-01'));
        self::assertSame([], $this->cuts([5], '2027-05-02'));
    }
}
