<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\FairnessPeriod;
use App\Entity\Planning;
use App\Entity\PlanningCycle;
use App\Entity\PlanningLine;
use App\Entity\PlanningLineType;
use App\Entity\PlanningPeriod;
use App\Entity\PlanningPeriodStatus;
use App\Entity\PlanningTeam;
use App\Entity\User;
use App\Exception\MultiplePeriodsNotSupportedException;
use App\Exception\PlanningLineWithoutPeriodException;
use App\Exception\PlanningPeriodLockedException;
use PHPUnit\Framework\TestCase;

/**
 * PlanningCycle and the attachment of a line's period to its cycle and line
 * (docs/planning-cycles.md §2-§3, lot L1). Pure entity tests, no kernel.
 */
final class PlanningCycleTest extends TestCase
{
    private function d(string $date): \DateTimeImmutable
    {
        return new \DateTimeImmutable($date);
    }

    private function planning(string $starts = '2027-01-01', string $ends = '2027-05-01'): Planning
    {
        return new Planning('P', new User('a@example.com', 'Ada', 'Lovelace', 'hash'), $this->d($starts), $this->d($ends), 'Europe/Brussels');
    }

    private function period(Planning $planning, string $starts = '2027-01-01', string $ends = '2027-05-01'): PlanningPeriod
    {
        $team = new PlanningTeam($planning, 'T');
        $fairness = new FairnessPeriod($team, 'F', $this->d($starts), $this->d($ends));

        return new PlanningPeriod($team, $fairness, 'PP', $this->d($starts), $this->d($ends));
    }

    public function testACycleIsNumberedFromOneWithAPositiveRange(): void
    {
        $planning = $this->planning();
        $cycle = new PlanningCycle($planning, 1, $this->d('2027-01-01'), $this->d('2027-05-01'), null);

        self::assertSame(1, $cycle->getNumber());
        self::assertSame($planning, $cycle->getPlanning());
        self::assertNull($cycle->getCreatedBy());
        self::assertSame('7', $cycle->getStableId()->toRfc4122()[14], 'stable ids are UUIDv7, like every other entity');

        foreach ([[0, '2027-01-01', '2027-05-01'], [1, '2027-05-01', '2027-05-01'], [1, '2027-05-01', '2027-01-01']] as [$number, $starts, $ends]) {
            try {
                new PlanningCycle($planning, $number, $this->d($starts), $this->d($ends), null);
                self::fail(\sprintf('expected a refusal for #%d %s → %s', $number, $starts, $ends));
            } catch (\InvalidArgumentException) {
            }
        }
    }

    public function testDatesAreComparedAsCalendarDays(): void
    {
        $cycle = new PlanningCycle($this->planning(), 1, $this->d('2027-01-01'), $this->d('2027-05-01'), null);

        self::assertTrue($cycle->hasSameDatesAs($this->d('2027-01-01 00:00'), $this->d('2027-05-01 00:00')));
        self::assertFalse($cycle->hasSameDatesAs($this->d('2027-01-01'), $this->d('2027-05-02')));
        self::assertTrue($cycle->contains($this->d('2027-01-01'), $this->d('2027-05-01')));
        self::assertTrue($cycle->contains($this->d('2027-02-01'), $this->d('2027-03-01')));
        self::assertFalse($cycle->contains($this->d('2026-12-31'), $this->d('2027-03-01')));
        self::assertFalse($cycle->contains($this->d('2027-02-01'), $this->d('2027-05-02')));
    }

    public function testACycleOnlyGrows(): void
    {
        $cycle = new PlanningCycle($this->planning(), 1, $this->d('2027-01-01'), $this->d('2027-05-01'), null);

        $cycle->extendTo($this->d('2026-12-01'), $this->d('2027-08-01'));
        self::assertSame('2026-12-01', $cycle->getStartsAt()->format('Y-m-d'));
        self::assertSame('2027-08-01', $cycle->getEndsAt()->format('Y-m-d'));

        $this->expectException(\InvalidArgumentException::class);
        $cycle->extendTo($this->d('2027-01-01'), $this->d('2027-08-01'));
    }

    public function testAPeriodAttachesOnceToACycleOfItsPlanningWithTheSameDates(): void
    {
        $planning = $this->planning();
        $period = $this->period($planning);
        $cycle = new PlanningCycle($planning, 1, $this->d('2027-01-01'), $this->d('2027-05-01'), null);

        $period->attachToCycle($cycle);
        $period->attachToCycle($cycle);
        self::assertSame($cycle, $period->getCycle());

        $this->expectException(\LogicException::class);
        $period->attachToCycle(new PlanningCycle($planning, 2, $this->d('2027-01-01'), $this->d('2027-05-01'), null));
    }

    public function testAPeriodRefusesACycleOfAnotherPlanningOrWithOtherDates(): void
    {
        $planning = $this->planning();

        try {
            $this->period($planning)->attachToCycle(new PlanningCycle($this->planning(), 1, $this->d('2027-01-01'), $this->d('2027-05-01'), null));
            self::fail('expected a refusal: cycle of another planning');
        } catch (\InvalidArgumentException) {
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->period($planning)->attachToCycle(new PlanningCycle($planning, 1, $this->d('2027-01-01'), $this->d('2027-06-01'), null));
    }

    public function testALineAndItsPeriodPointAtEachOther(): void
    {
        $planning = $this->planning();
        $period = $this->period($planning);
        $period->attachToCycle(new PlanningCycle($planning, 1, $this->d('2027-01-01'), $this->d('2027-05-01'), null));
        $line = new PlanningLine($planning, $period->getTeam(), $period, 'L', PlanningLineType::PRIMARY, 1);

        self::assertSame($line, $period->getLine());
        self::assertSame($period, $line->requireSingleCyclePeriod());
        self::assertSame([$period], $line->getPeriods());

        $this->expectException(\LogicException::class);
        $period->bindToLine(new PlanningLine($planning, $period->getTeam(), null, 'Other', PlanningLineType::SECONDARY, 2));
    }

    public function testALineRefusesAFirstPeriodWithoutCycleOfItsPlanning(): void
    {
        $planning = $this->planning();

        $this->expectException(\InvalidArgumentException::class);
        new PlanningLine($planning, ($period = $this->period($planning))->getTeam(), $period, 'L', PlanningLineType::PRIMARY, 1);
    }

    /**
     * Lot L2 (D187): a line has one period per cycle; the flows not period-aware
     * yet read "the" period only while there is a single cycle, never a guess.
     */
    public function testALineHasOnePeriodPerCycleAndTheSinglePeriodAccessorRefusesSeveralCycles(): void
    {
        $planning = $this->planning('2027-01-01', '2027-09-01');
        $cycle1 = new PlanningCycle($planning, 1, $this->d('2027-01-01'), $this->d('2027-05-01'), null);
        $team = new PlanningTeam($planning, 'T');
        $fairness = new FairnessPeriod($team, 'F', $this->d('2027-01-01'), $this->d('2027-09-01'));
        $first = new PlanningPeriod($team, $fairness, 'PP', $this->d('2027-01-01'), $this->d('2027-05-01'));
        $first->attachToCycle($cycle1);
        $line = new PlanningLine($planning, $team, $first, 'L', PlanningLineType::PRIMARY, 1);

        self::assertSame($cycle1, $planning->getOnlyCycle());
        self::assertSame($first, $line->getSingleCyclePeriod());

        $cycle2 = new PlanningCycle($planning, 2, $this->d('2027-05-01'), $this->d('2027-09-01'), null);
        $second = new PlanningPeriod($team, $fairness, 'PP', $this->d('2027-05-01'), $this->d('2027-09-01'));
        $second->attachToCycle($cycle2);
        $second->bindToLine($line);

        self::assertSame([$cycle1, $cycle2], $planning->getCycles());
        self::assertSame([$first, $second], $line->getPeriods());
        self::assertSame($second, $line->periodIn($cycle2));
        foreach ([fn () => $line->getSingleCyclePeriod(), fn () => $line->requireSingleCyclePeriod(), fn () => $planning->getOnlyCycle(), fn () => $planning->assertSingleCycle()] as $call) {
            try {
                $call();
                self::fail('expected a refusal: the planning has several periods');
            } catch (MultiplePeriodsNotSupportedException $exception) {
                self::assertSame(409, $exception->statusCode());
                self::assertSame('multiple_periods_not_supported', $exception->errorCode());
            }
        }
    }

    public function testALineWithoutPeriodHasNothingToRead(): void
    {
        $planning = $this->planning();
        new PlanningCycle($planning, 1, $this->d('2027-01-01'), $this->d('2027-05-01'), null);
        $line = new PlanningLine($planning, new PlanningTeam($planning, 'T'), null, 'L', PlanningLineType::SECONDARY, 2);

        self::assertNull($line->getSingleCyclePeriod());
        self::assertSame([], $line->getPeriods());

        $this->expectException(PlanningLineWithoutPeriodException::class);
        $line->requireSingleCyclePeriod();
    }

    public function testTheEndOfTheLastPeriodMovesBothWaysAfterItsCycleUnlessLocked(): void
    {
        $planning = $this->planning();
        $cycle = new PlanningCycle($planning, 1, $this->d('2027-01-01'), $this->d('2027-05-01'), null);
        $period = $this->period($planning);
        $period->attachToCycle($cycle);

        try {
            $period->changeEnd($this->d('2027-04-01'));
            self::fail('expected a refusal: the cycle did not move first');
        } catch (\InvalidArgumentException) {
        }

        $cycle->changeEnd($this->d('2027-04-01'));
        $period->changeEnd($this->d('2027-04-01'));
        self::assertSame('2027-04-01', $period->getEndsAt()->format('Y-m-d'));
        self::assertTrue($cycle->containsDay($this->d('2027-03-31')));
        self::assertFalse($cycle->containsDay($this->d('2027-04-01')));

        $cycle->changeEnd($this->d('2027-06-01'));
        try {
            $period->changeEnd($this->d('2027-06-01'));
            self::fail('expected a refusal: beyond the FairnessPeriod');
        } catch (\InvalidArgumentException) {
        }
        $period->getFairnessPeriod()->extendTo($this->d('2027-01-01'), $this->d('2027-06-01'));
        $period->changeEnd($this->d('2027-06-01'));
        $period->getFairnessPeriod()->changeEnd($this->d('2027-06-01'));

        $period->transitionTo(PlanningPeriodStatus::GENERATED);
        $period->transitionTo(PlanningPeriodStatus::VALIDATED);
        $cycle->changeEnd($this->d('2027-07-01'));
        $this->expectException(PlanningPeriodLockedException::class);
        $period->changeEnd($this->d('2027-07-01'));
    }

    public function testAnAttachedPeriodGrowsOnlyAfterItsCycle(): void
    {
        $planning = $this->planning();
        $period = $this->period($planning);
        $cycle = new PlanningCycle($planning, 1, $this->d('2027-01-01'), $this->d('2027-05-01'), null);
        $period->attachToCycle($cycle);
        $period->getFairnessPeriod()->extendTo($this->d('2027-01-01'), $this->d('2027-08-01'));

        try {
            $period->extendTo($this->d('2027-01-01'), $this->d('2027-08-01'));
            self::fail('expected a refusal: the cycle was not extended first');
        } catch (\InvalidArgumentException) {
        }

        $cycle->extendTo($this->d('2027-01-01'), $this->d('2027-08-01'));
        $period->extendTo($this->d('2027-01-01'), $this->d('2027-08-01'));
        self::assertSame('2027-08-01', $period->getEndsAt()->format('Y-m-d'));
    }
}
