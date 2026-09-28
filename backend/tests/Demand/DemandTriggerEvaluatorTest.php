<?php

declare(strict_types=1);

namespace App\Tests\Demand;

use App\Demand\DemandMode;
use App\Demand\DemandRules;
use App\Demand\DemandTriggerEvaluator;
use App\Demand\DemandTriggerRule;
use App\Demand\Weekday;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The pure answer to "does this person, holding the source line on this
 * weekday, trigger a reinforcement?" (docs/decisions.md D162) — no kernel,
 * no database: plain values in, the matching trigger out.
 *
 * Running example: Dr A has no trigger, Dr B triggers every day, Dr C only
 * on Friday, Saturday and Sunday.
 */
final class DemandTriggerEvaluatorTest extends TestCase
{
    private const DR_A = 'a0000000-0000-7000-8000-000000000001';
    private const DR_B = 'b0000000-0000-7000-8000-000000000002';
    private const DR_C = 'c0000000-0000-7000-8000-000000000003';
    private const SOURCE_LINE = 'd0000000-0000-7000-8000-000000000004';

    private function rules(): DemandRules
    {
        return new DemandRules(DemandMode::CONDITIONAL_ON_SOURCE_ASSIGNMENT, self::SOURCE_LINE, [
            new DemandTriggerRule(self::DR_B, Weekday::cases(), 1, 'trigger-b'),
            new DemandTriggerRule(self::DR_C, [Weekday::SUNDAY, Weekday::FRIDAY, Weekday::SATURDAY], 1, 'trigger-c'),
        ]);
    }

    /**
     * @return iterable<string, array{0: string, 1: Weekday, 2: bool}>
     */
    public static function matrix(): iterable
    {
        foreach (Weekday::cases() as $day) {
            yield "Dr A, {$day->value}" => [self::DR_A, $day, false];
            yield "Dr B, {$day->value}" => [self::DR_B, $day, true];
            yield "Dr C, {$day->value}" => [self::DR_C, $day, \in_array($day, [Weekday::FRIDAY, Weekday::SATURDAY, Weekday::SUNDAY], true)];
        }
    }

    #[DataProvider('matrix')]
    public function testTheExpectedMatrix(string $sourceUser, Weekday $weekday, bool $expected): void
    {
        self::assertSame($expected, (new DemandTriggerEvaluator())->isTriggered($this->rules(), $sourceUser, $weekday));
    }

    public function testThePersonWithoutATriggerNeverTriggers(): void
    {
        self::assertNull((new DemandTriggerEvaluator())->matchingTrigger($this->rules(), self::DR_A, Weekday::SATURDAY));
    }

    public function testATriggeredDayReturnsTheMatchingTrigger(): void
    {
        $match = (new DemandTriggerEvaluator())->matchingTrigger($this->rules(), self::DR_C, Weekday::SATURDAY);

        self::assertSame('trigger-c', $match?->triggerStableId);
        self::assertSame(1, $match->increment);
    }

    public function testAConcernedDayWithTheWrongPersonDoesNotTrigger(): void
    {
        // Friday is a day Dr C triggers — but Dr A holds the source line.
        self::assertFalse((new DemandTriggerEvaluator())->isTriggered($this->rules(), self::DR_A, Weekday::FRIDAY));
    }

    public function testTheRightPersonOnAnotherDayDoesNotTrigger(): void
    {
        self::assertFalse((new DemandTriggerEvaluator())->isTriggered($this->rules(), self::DR_C, Weekday::TUESDAY));
    }

    public function testNobodyHoldingTheSourceDutyNeverTriggers(): void
    {
        self::assertNull((new DemandTriggerEvaluator())->matchingTrigger($this->rules(), null, Weekday::FRIDAY));
    }

    public function testAnIndependentLineNeverTriggers(): void
    {
        self::assertFalse((new DemandTriggerEvaluator())->isTriggered(DemandRules::independent(), self::DR_B, Weekday::MONDAY));
    }

    public function testWeekdaysAreNormalizedToIsoOrder(): void
    {
        $rule = new DemandTriggerRule(self::DR_C, [Weekday::SUNDAY, Weekday::FRIDAY, Weekday::SATURDAY, Weekday::FRIDAY], 1);

        self::assertSame([Weekday::FRIDAY, Weekday::SATURDAY, Weekday::SUNDAY], $rule->weekdays);
    }

    public function testATriggerNeedsAWeekdayAndAPositiveIncrement(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new DemandTriggerRule(self::DR_B, [], 1);
    }

    public function testAnIncrementBelowOneIsImpossible(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new DemandTriggerRule(self::DR_B, [Weekday::MONDAY], 0);
    }

    public function testTwoTriggersForOnePersonAreImpossible(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new DemandRules(DemandMode::CONDITIONAL_ON_SOURCE_ASSIGNMENT, self::SOURCE_LINE, [
            new DemandTriggerRule(self::DR_B, [Weekday::MONDAY], 1),
            new DemandTriggerRule(self::DR_B, [Weekday::TUESDAY], 1),
        ]);
    }

    public function testAnIndependentDemandHasNeitherSourceNorTrigger(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new DemandRules(DemandMode::INDEPENDENT, self::SOURCE_LINE, []);
    }

    public function testAConditionalDemandNeedsASource(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new DemandRules(DemandMode::CONDITIONAL_ON_SOURCE_ASSIGNMENT, null, []);
    }

    public function testWeekdayOfADateAndWeekStructureCodes(): void
    {
        self::assertSame(Weekday::FRIDAY, Weekday::ofDate(new \DateTimeImmutable('2027-01-08')));
        self::assertSame('VEN', Weekday::FRIDAY->weekStructureCode());
        self::assertSame(Weekday::SUNDAY, Weekday::fromWeekStructureCode('DIM'));
        self::assertSame(1, Weekday::MONDAY->isoNumber());
        self::assertSame(7, Weekday::SUNDAY->isoNumber());
    }
}
