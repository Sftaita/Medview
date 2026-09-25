<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Eligibility\DutyGroupUnit;
use App\Eligibility\SingleDutyUnit;
use App\Entity\AllocationFamily;
use App\Entity\Duty;
use App\Entity\DutyDemandType;
use App\Entity\DutyGroupInstance;
use App\Entity\DutyPattern;
use App\Entity\DutyType;
use App\Entity\FairnessPeriod;
use App\Entity\Planning;
use App\Entity\PlanningPeriod;
use App\Entity\PlanningTeam;
use App\Entity\User;
use App\Fairness\FairnessDimensionKey;
use App\Fairness\FairnessDimensionValues;
use App\Service\DimensionMembershipCalculator;
use PHPUnit\Framework\TestCase;

/**
 * docs/decisions.md D136 — ALLOCATION_FAMILY is the one dimension counted
 * once per *unit*, never once per constituent Duty (Scenario F of the
 * audit): a 3-day WEEKEND block must credit `ALLOCATION_FAMILY:WEEKEND +=
 * 1`, never `+= 3`, while its calendar dimensions (FRIDAY/SATURDAY/SUNDAY/
 * TOTAL_DUTIES) still credit once per Duty exactly as before.
 */
final class DimensionMembershipCalculatorTest extends TestCase
{
    private function newPlanningPeriod(): PlanningPeriod
    {
        $planning = new Planning(
            'Test Planning',
            new User('creator@example.com', 'Creator', 'User', 'hash'),
            new \DateTimeImmutable('2027-01-01'),
            new \DateTimeImmutable('2028-01-01'),
            'Europe/Brussels',
        );
        $team = new PlanningTeam($planning, 'Cardiology');
        $fairnessPeriod = new FairnessPeriod($team, '2027', new \DateTimeImmutable('2027-01-01'), new \DateTimeImmutable('2028-01-01'));

        return new PlanningPeriod($team, $fairnessPeriod, 'Jan-Apr', new \DateTimeImmutable('2027-01-01'), new \DateTimeImmutable('2027-05-01'));
    }

    private function instant(string $localDate, string $time, string $timezone = 'Europe/Brussels'): \DateTimeImmutable
    {
        return new \DateTimeImmutable("{$localDate} {$time}", new \DateTimeZone($timezone));
    }

    public function testAWeekendBlockCreditsTheFamilyOnceButCalendarDimensionsPerDay(): void
    {
        $period = $this->newPlanningPeriod();
        $team = $period->getTeam();
        $dutyType = new DutyType($team, 'GARDE', 'Garde');
        $family = new AllocationFamily($team, 'WEEKEND', 'Week-end');
        $pattern = new DutyPattern($team, 'VSD', 'Week-end', $family);
        $pattern->addComponent(0, $dutyType);
        $pattern->addComponent(1, $dutyType);
        $pattern->addComponent(2, $dutyType);

        // 2027-03-12 is a Friday.
        $group = new DutyGroupInstance($period, $pattern, new \DateTimeImmutable('2027-03-12'));
        $friday = new Duty($period, $dutyType, $this->instant('2027-03-12', '08:00'), $this->instant('2027-03-13', '08:00'), 'Europe/Brussels', DutyDemandType::REQUIRED, groupInstance: $group);
        $saturday = new Duty($period, $dutyType, $this->instant('2027-03-13', '08:00'), $this->instant('2027-03-14', '08:00'), 'Europe/Brussels', DutyDemandType::REQUIRED, groupInstance: $group);
        $sunday = new Duty($period, $dutyType, $this->instant('2027-03-14', '08:00'), $this->instant('2027-03-15', '08:00'), 'Europe/Brussels', DutyDemandType::REQUIRED, groupInstance: $group);

        $unit = new DutyGroupUnit($group, [$friday, $saturday, $sunday]);

        $calculator = new DimensionMembershipCalculator();
        $values = $calculator->forDutyUnit($unit);

        self::assertSame(3.0, $values->get(FairnessDimensionKey::totalDuties()));
        self::assertSame(1.0, $values->get(FairnessDimensionKey::friday()));
        self::assertSame(1.0, $values->get(FairnessDimensionKey::saturday()));
        self::assertSame(1.0, $values->get(FairnessDimensionKey::sunday()));
        // The Scenario F guarantee: exactly 1, never 3.
        self::assertSame(1.0, $values->get(FairnessDimensionKey::allocationFamily((string) $family->getStableId())));
    }

    public function testTwoWeekendBlocksAssignedToTheSamePersonSumToTwoFamilyUnitsAndSixTotalDuties(): void
    {
        $period = $this->newPlanningPeriod();
        $team = $period->getTeam();
        $dutyType = new DutyType($team, 'GARDE', 'Garde');
        $family = new AllocationFamily($team, 'WEEKEND', 'Week-end');
        $pattern = new DutyPattern($team, 'VSD', 'Week-end', $family);
        $pattern->addComponent(0, $dutyType);
        $pattern->addComponent(1, $dutyType);
        $pattern->addComponent(2, $dutyType);

        $calculator = new DimensionMembershipCalculator();
        $total = FairnessDimensionValues::empty();

        foreach (['2027-03-12', '2027-03-19'] as $anchor) {
            $group = new DutyGroupInstance($period, $pattern, new \DateTimeImmutable($anchor));
            $friday = new Duty($period, $dutyType, $this->instant($anchor, '08:00'), $this->instant((new \DateTimeImmutable($anchor))->modify('+1 day')->format('Y-m-d'), '08:00'), 'Europe/Brussels', DutyDemandType::REQUIRED, groupInstance: $group);
            $saturdayDate = (new \DateTimeImmutable($anchor))->modify('+1 day')->format('Y-m-d');
            $sundayDate = (new \DateTimeImmutable($anchor))->modify('+2 days')->format('Y-m-d');
            $saturday = new Duty($period, $dutyType, $this->instant($saturdayDate, '08:00'), $this->instant($sundayDate, '08:00'), 'Europe/Brussels', DutyDemandType::REQUIRED, groupInstance: $group);
            $sunday = new Duty($period, $dutyType, $this->instant($sundayDate, '08:00'), $this->instant((new \DateTimeImmutable($sundayDate))->modify('+1 day')->format('Y-m-d'), '08:00'), 'Europe/Brussels', DutyDemandType::REQUIRED, groupInstance: $group);

            $unit = new DutyGroupUnit($group, [$friday, $saturday, $sunday]);
            $total = $total->plus($calculator->forDutyUnit($unit));
        }

        self::assertSame(6.0, $total->get(FairnessDimensionKey::totalDuties()));
        self::assertSame(2.0, $total->get(FairnessDimensionKey::friday()));
        self::assertSame(2.0, $total->get(FairnessDimensionKey::saturday()));
        self::assertSame(2.0, $total->get(FairnessDimensionKey::sunday()));
        // Scenario F: exactly 2 — never 6, never equal to totalDuties.
        self::assertSame(2.0, $total->get(FairnessDimensionKey::allocationFamily((string) $family->getStableId())));
    }

    public function testASoloDutyWithNoPatternContributesNoAllocationFamily(): void
    {
        $period = $this->newPlanningPeriod();
        $dutyType = new DutyType($period->getTeam(), 'GARDE', 'Garde');
        $duty = new Duty($period, $dutyType, $this->instant('2027-01-04', '08:00'), $this->instant('2027-01-05', '08:00'), 'Europe/Brussels');

        $values = (new DimensionMembershipCalculator())->forDutyUnit(new SingleDutyUnit($duty));

        self::assertNull($duty->getAllocationFamily());
        self::assertSame(1.0, $values->get(FairnessDimensionKey::totalDuties()));
        self::assertSame(0.0, $values->get(FairnessDimensionKey::allocationFamily('does-not-matter-never-set')));
    }

    public function testASoloDutyMaterializedFromAOneComponentPatternContributesItsFamilyOnce(): void
    {
        $period = $this->newPlanningPeriod();
        $team = $period->getTeam();
        $dutyType = new DutyType($team, 'GARDE', 'Garde');
        $family = new AllocationFamily($team, 'WEEKDAY', 'Semaine');
        $pattern = new DutyPattern($team, 'LUN', 'Lundi', $family);
        $pattern->addComponent(0, $dutyType);

        $duty = new Duty($period, $dutyType, $this->instant('2027-01-04', '08:00'), $this->instant('2027-01-05', '08:00'), 'Europe/Brussels', pattern: $pattern);

        $values = (new DimensionMembershipCalculator())->forDutyUnit(new SingleDutyUnit($duty));

        self::assertSame(1.0, $values->get(FairnessDimensionKey::allocationFamily((string) $family->getStableId())));
    }
}
