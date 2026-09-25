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
use App\Service\SpacingPenaltyCalculator;
use PHPUnit\Framework\TestCase;

/**
 * docs/decisions.md D139 — the real `SPACING_SCORE` metric. Pure, no
 * kernel/DB needed (same convention as DimensionMembershipCalculatorTest):
 * every fact this class needs is already on the plain entity graph.
 */
final class SpacingPenaltyCalculatorTest extends TestCase
{
    private PlanningPeriod $period;
    private PlanningTeam $team;
    private DutyType $dutyType;

    protected function setUp(): void
    {
        $planning = new Planning(
            'Test Planning',
            new User('creator@example.com', 'Creator', 'User', 'hash'),
            new \DateTimeImmutable('2027-01-01'),
            new \DateTimeImmutable('2028-01-01'),
            'Europe/Brussels',
        );
        $this->team = new PlanningTeam($planning, 'Cardiology');
        $fairnessPeriod = new FairnessPeriod($this->team, '2027', new \DateTimeImmutable('2027-01-01'), new \DateTimeImmutable('2028-01-01'));
        $this->period = new PlanningPeriod($this->team, $fairnessPeriod, 'Jan-Apr', new \DateTimeImmutable('2027-01-01'), new \DateTimeImmutable('2027-05-01'));
        $this->dutyType = new DutyType($this->team, 'GARDE', 'Garde');
    }

    private function soloUnit(string $localDate, ?AllocationFamily $family = null): SingleDutyUnit
    {
        $pattern = null !== $family ? new DutyPattern($this->team, 'P-'.$localDate, 'Solo', $family) : null;
        $duty = new Duty(
            $this->period,
            $this->dutyType,
            new \DateTimeImmutable("{$localDate} 08:00", new \DateTimeZone('Europe/Brussels')),
            (new \DateTimeImmutable("{$localDate} 08:00", new \DateTimeZone('Europe/Brussels')))->modify('+1 day'),
            'Europe/Brussels',
            DutyDemandType::REQUIRED,
            pattern: $pattern,
        );

        return new SingleDutyUnit($duty);
    }

    /** A Ven/Sam/Dim-shaped 3-day block anchored on $fridayDate. */
    private function blockUnit(string $fridayDate, ?AllocationFamily $family = null): DutyGroupUnit
    {
        $pattern = new DutyPattern($this->team, 'BLOCK-'.$fridayDate, 'Bloc', $family);
        $group = new DutyGroupInstance($this->period, $pattern, new \DateTimeImmutable($fridayDate));

        $duties = [];
        $cursor = new \DateTimeImmutable($fridayDate);
        for ($i = 0; $i < 3; ++$i) {
            $start = $cursor->modify("+{$i} days");
            $duties[] = new Duty(
                $this->period,
                $this->dutyType,
                new \DateTimeImmutable($start->format('Y-m-d').' 08:00', new \DateTimeZone('Europe/Brussels')),
                (new \DateTimeImmutable($start->format('Y-m-d').' 08:00', new \DateTimeZone('Europe/Brussels')))->modify('+1 day'),
                'Europe/Brussels',
                DutyDemandType::REQUIRED,
                groupInstance: $group,
            );
        }

        return new DutyGroupUnit($group, $duties);
    }

    private function penaltyBetween(array $penalties, string $keyA, string $keyB): ?int
    {
        foreach ($penalties as $p) {
            if (($p->unitAKey === $keyA && $p->unitBKey === $keyB) || ($p->unitAKey === $keyB && $p->unitBKey === $keyA)) {
                return $p->penalty;
            }
        }

        return null;
    }

    public function testZeroFreeDaysBetweenTwoSoloUnitsGetsTheStrongestTier(): void
    {
        $a = $this->soloUnit('2027-01-04');
        $b = $this->soloUnit('2027-01-05');

        $penalties = (new SpacingPenaltyCalculator())->buildPairPenalties([$a, $b]);

        self::assertSame(100, $this->penaltyBetween($penalties, $a->getStableKey(), $b->getStableKey()));
    }

    public function testOneFreeDayGetsALighterTierThanZero(): void
    {
        $a = $this->soloUnit('2027-01-04');
        $b = $this->soloUnit('2027-01-06');

        $penalties = (new SpacingPenaltyCalculator())->buildPairPenalties([$a, $b]);

        self::assertSame(60, $this->penaltyBetween($penalties, $a->getStableKey(), $b->getStableKey()));
    }

    public function testTwoFreeDaysGetsTheLightestNonZeroTier(): void
    {
        $a = $this->soloUnit('2027-01-04');
        $b = $this->soloUnit('2027-01-07');

        $penalties = (new SpacingPenaltyCalculator())->buildPairPenalties([$a, $b]);

        self::assertSame(20, $this->penaltyBetween($penalties, $a->getStableKey(), $b->getStableKey()));
    }

    public function testThreeOrMoreFreeDaysCarriesNoPenaltyAtAll(): void
    {
        $a = $this->soloUnit('2027-01-04');
        $b = $this->soloUnit('2027-01-08');

        $penalties = (new SpacingPenaltyCalculator())->buildPairPenalties([$a, $b]);

        self::assertNull($this->penaltyBetween($penalties, $a->getStableKey(), $b->getStableKey()));
        self::assertSame([], $penalties);
    }

    public function testABlockIsOneSpanNeverPenalizedAgainstItsOwnConstituentDays(): void
    {
        $block = $this->blockUnit('2027-01-08'); // Fri 8, Sat 9, Sun 10

        $penalties = (new SpacingPenaltyCalculator())->buildPairPenalties([$block]);

        self::assertSame([], $penalties, 'a single DutyUnit is never compared against itself');
    }

    public function testABlockImmediatelyFollowedByASoloUnitGetsTheZeroFreeDaysTier(): void
    {
        $block = $this->blockUnit('2027-01-08'); // ends Sunday 2027-01-10
        $monday = $this->soloUnit('2027-01-11');

        $penalties = (new SpacingPenaltyCalculator())->buildPairPenalties([$block, $monday]);

        self::assertSame(100, $this->penaltyBetween($penalties, $block->getStableKey(), $monday->getStableKey()), 'freeDays is computed from the block\'s real endDate, never per constituent day');
    }

    public function testASoloUnitImmediatelyBeforeABlockGetsTheZeroFreeDaysTier(): void
    {
        $thursday = $this->soloUnit('2027-01-07');
        $block = $this->blockUnit('2027-01-08'); // starts Friday 2027-01-08

        $penalties = (new SpacingPenaltyCalculator())->buildPairPenalties([$thursday, $block]);

        self::assertSame(100, $this->penaltyBetween($penalties, $thursday->getStableKey(), $block->getStableKey()));
    }

    public function testConsecutiveOccurrencesOfTheSameFamilyArePenalizedEvenBeyondTheFreeDaysHorizon(): void
    {
        $family = new AllocationFamily($this->team, 'WEEKEND', 'Week-end');
        $week1 = $this->blockUnit('2027-01-08', $family); // ends Sun 2027-01-10
        $week2 = $this->blockUnit('2027-01-15', $family); // starts Fri 2027-01-15 — freeDays = 4, beyond the tier horizon

        $penalties = (new SpacingPenaltyCalculator())->buildPairPenalties([$week1, $week2]);

        self::assertSame(40, $this->penaltyBetween($penalties, $week1->getStableKey(), $week2->getStableKey()), 'same-family repetition is a distinct signal from raw calendar adjacency');
    }

    public function testNonConsecutiveOccurrencesOfTheSameFamilySkippingOneAreNeverPenalized(): void
    {
        $family = new AllocationFamily($this->team, 'WEEKEND', 'Week-end');
        $week1 = $this->blockUnit('2027-01-08', $family);
        $week2 = $this->blockUnit('2027-01-15', $family);
        $week3 = $this->blockUnit('2027-01-22', $family);

        $penalties = (new SpacingPenaltyCalculator())->buildPairPenalties([$week1, $week2, $week3]);

        self::assertNull($this->penaltyBetween($penalties, $week1->getStableKey(), $week3->getStableKey()), 'week1 and week3 are not consecutive in the family sequence — week2 sits between them');
        self::assertNotNull($this->penaltyBetween($penalties, $week1->getStableKey(), $week2->getStableKey()));
        self::assertNotNull($this->penaltyBetween($penalties, $week2->getStableKey(), $week3->getStableKey()));
    }

    public function testDifferentFamiliesAreNeverCrossPenalizedByTheFamilyRule(): void
    {
        $weekend = new AllocationFamily($this->team, 'WEEKEND', 'Week-end');
        $weekday = new AllocationFamily($this->team, 'WEEKDAY', 'Semaine');
        $block = $this->blockUnit('2027-01-08', $weekend); // ends Sun 2027-01-10
        $farAway = $this->soloUnit('2027-01-20', $weekday); // 9 free days later, different family

        $penalties = (new SpacingPenaltyCalculator())->buildPairPenalties([$block, $farAway]);

        self::assertSame([], $penalties);
    }

    public function testSameFamilyPairWithinTheFreeDaysHorizonAccumulatesBothPenaltiesAdditively(): void
    {
        $family = new AllocationFamily($this->team, 'WEEKDAY', 'Semaine');
        $a = $this->soloUnit('2027-01-04', $family);
        $b = $this->soloUnit('2027-01-05', $family);

        $penalties = (new SpacingPenaltyCalculator())->buildPairPenalties([$a, $b]);

        self::assertSame(140, $this->penaltyBetween($penalties, $a->getStableKey(), $b->getStableKey()), '100 (zero free days) + 40 (same-family consecutive) — never averaged, never picking only one');
    }

    public function testNoAllocationFamilyMeansTheFamilyRuleNeverApplies(): void
    {
        $a = $this->soloUnit('2027-01-08'); // no family
        $b = $this->soloUnit('2027-01-15'); // no family, far apart

        $penalties = (new SpacingPenaltyCalculator())->buildPairPenalties([$a, $b]);

        self::assertSame([], $penalties);
    }
}
