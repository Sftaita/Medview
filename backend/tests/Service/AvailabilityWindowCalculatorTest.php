<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Exception\NoNewPlanningRangeException;
use App\Exception\PlanningRangeShrinkException;
use App\Service\AvailabilityWindowCalculator;
use App\Service\DateWindow;
use PHPUnit\Framework\TestCase;

/**
 * Pure unit tests (no kernel): which dates does a planning extension make new?
 */
final class AvailabilityWindowCalculatorTest extends TestCase
{
    private AvailabilityWindowCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new AvailabilityWindowCalculator();
    }

    private function d(string $date): \DateTimeImmutable
    {
        return new \DateTimeImmutable($date);
    }

    /**
     * @param list<DateWindow> $windows
     *
     * @return list<string>
     */
    private function format(array $windows): array
    {
        return array_map(
            static fn (DateWindow $w): string => $w->startsAt->format('Y-m-d').'/'.$w->endsAt->format('Y-m-d'),
            $windows,
        );
    }

    public function testExtendingTheEndCollectsOnlyTheAddedTail(): void
    {
        // 01/09 → 31/12 becomes 01/09 → 31/03: only 01/01 → 31/03 is new.
        $windows = $this->calculator->newWindows($this->d('2026-09-01'), $this->d('2027-01-01'), $this->d('2026-09-01'), $this->d('2027-04-01'));

        self::assertSame(['2027-01-01/2027-04-01'], $this->format($windows));
    }

    public function testExtendingTheStartCollectsOnlyTheAddedHead(): void
    {
        $windows = $this->calculator->newWindows($this->d('2026-09-01'), $this->d('2027-01-01'), $this->d('2026-07-01'), $this->d('2027-01-01'));

        self::assertSame(['2026-07-01/2026-09-01'], $this->format($windows));
    }

    public function testExtendingBothSidesYieldsTwoDisjointWindowsAndNeverTheExistingDates(): void
    {
        $windows = $this->calculator->newWindows($this->d('2026-09-01'), $this->d('2027-01-01'), $this->d('2026-08-01'), $this->d('2027-02-01'));

        self::assertSame(['2026-08-01/2026-09-01', '2027-01-01/2027-02-01'], $this->format($windows));
    }

    public function testSuccessiveExtensionsEachCollectOnlyTheirOwnSlice(): void
    {
        $first = $this->calculator->newWindows($this->d('2026-09-01'), $this->d('2027-01-01'), $this->d('2026-09-01'), $this->d('2027-04-01'));
        $second = $this->calculator->newWindows($this->d('2026-09-01'), $this->d('2027-04-01'), $this->d('2026-09-01'), $this->d('2027-07-01'));

        self::assertSame(['2027-01-01/2027-04-01'], $this->format($first));
        self::assertSame(['2027-04-01/2027-07-01'], $this->format($second));
        // Adjacent, never overlapping: a date is asked about exactly once.
        self::assertEquals($first[0]->endsAt, $second[0]->startsAt);
    }

    public function testASingleDayExtensionIsStillAWindow(): void
    {
        $windows = $this->calculator->newWindows($this->d('2027-01-01'), $this->d('2027-02-01'), $this->d('2027-01-01'), $this->d('2027-02-02'));

        self::assertSame(['2027-02-01/2027-02-02'], $this->format($windows));
    }

    public function testAnUnchangedRangeIsNeverAnEmptyCollection(): void
    {
        $this->expectException(NoNewPlanningRangeException::class);

        $this->calculator->newWindows($this->d('2027-01-01'), $this->d('2027-05-01'), $this->d('2027-01-01'), $this->d('2027-05-01'));
    }

    public function testShrinkingTheEndIsRefused(): void
    {
        $this->expectException(PlanningRangeShrinkException::class);

        $this->calculator->newWindows($this->d('2027-01-01'), $this->d('2027-05-01'), $this->d('2027-01-01'), $this->d('2027-04-01'));
    }

    public function testShrinkingTheStartIsRefused(): void
    {
        $this->expectException(PlanningRangeShrinkException::class);

        $this->calculator->newWindows($this->d('2027-01-01'), $this->d('2027-05-01'), $this->d('2027-02-01'), $this->d('2027-05-01'));
    }

    public function testShrinkingOneSideWhileGrowingTheOtherIsRefusedNotSilentlyAccepted(): void
    {
        $this->expectException(PlanningRangeShrinkException::class);

        $this->calculator->newWindows($this->d('2027-01-01'), $this->d('2027-05-01'), $this->d('2027-02-01'), $this->d('2027-08-01'));
    }

    public function testADateWindowMustBeNonEmpty(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new DateWindow($this->d('2027-01-01'), $this->d('2027-01-01'));
    }
}
