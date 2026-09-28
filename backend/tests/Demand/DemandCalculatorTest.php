<?php

declare(strict_types=1);

namespace App\Tests\Demand;

use App\Demand\DemandCalculator;
use App\Demand\DemandMode;
use App\Demand\DemandReason;
use App\Demand\DemandRules;
use App\Demand\DemandTriggerRule;
use App\Demand\SourceHolding;
use App\Demand\Weekday;
use App\Entity\Duty;
use App\Entity\DutyDemandType;
use PHPUnit\Framework\TestCase;

/**
 * THE demand rule (docs/decisions.md D163) on plain inputs: no kernel, no
 * database. Running example — Dr A: no trigger; Dr B: every day; Dr C:
 * Friday to Sunday. 2027-01-05 is a Tuesday, 2027-01-08 a Friday.
 */
final class DemandCalculatorTest extends TestCase
{
    use InMemoryConditionalFixtures;

    private const DR_A = 'a0000000-0000-7000-8000-000000000001';
    private const DR_B = 'b0000000-0000-7000-8000-000000000002';
    private const DR_C = 'c0000000-0000-7000-8000-000000000003';

    /** @var array<int, SourceHolding> keyed by spl_object_id of the source duty */
    private array $holdings = [];

    protected function setUp(): void
    {
        $this->buildTwoLines();
        $this->holdings = [];
    }

    private function rules(): DemandRules
    {
        return new DemandRules(DemandMode::CONDITIONAL_ON_SOURCE_ASSIGNMENT, (string) $this->sourcePeriod->getStableId(), [
            new DemandTriggerRule(self::DR_B, Weekday::cases(), 1, 'trigger-b'),
            new DemandTriggerRule(self::DR_C, [Weekday::FRIDAY, Weekday::SATURDAY, Weekday::SUNDAY], 1, 'trigger-c'),
        ]);
    }

    private function heldBy(Duty $source, ?string $user): void
    {
        $this->holdings[spl_object_id($source)] = new SourceHolding(true, $user);
    }

    /**
     * @param list<Duty> $duties
     */
    private function compute(array $duties, ?DemandRules $rules = null): \App\Demand\UnitDemand
    {
        return (new DemandCalculator())->unit($duties, $rules ?? $this->rules(), fn (Duty $source): SourceHolding => $this->holdings[spl_object_id($source)] ?? SourceHolding::notGenerated());
    }

    // --- intrinsic duties: unchanged semantics --------------------------------------------------

    public function testARequiredDutyIsRequiredAsBefore(): void
    {
        $demand = $this->compute([$this->sourceDuty('2027-01-05')], DemandRules::independent());

        self::assertTrue($demand->required);
        self::assertSame(DemandReason::INTRINSIC_REQUIRED, $demand->duties[0]->reason);
        self::assertNull($demand->duties[0]->ownDay);
        self::assertFalse($demand->duties[0]->isConditional());
    }

    public function testAnOptionalDutyIsNotRequiredAsBefore(): void
    {
        $demand = $this->compute([$this->sourceDuty('2027-01-05', DutyDemandType::OPTIONAL)], DemandRules::independent());

        self::assertFalse($demand->required);
        self::assertSame(DemandReason::INTRINSIC_OPTIONAL, $demand->duties[0]->reason);
    }

    // --- one conditional day ---------------------------------------------------------------------

    public function testDrAWithoutTriggerDoesNotRequireAReinforcement(): void
    {
        $source = $this->sourceDuty('2027-01-08');
        $this->heldBy($source, self::DR_A);

        $demand = $this->compute([$this->conditionalDuty($source)])->duties[0];

        self::assertFalse($demand->required);
        self::assertSame(DemandReason::HOLDER_HAS_NO_TRIGGER, $demand->reason);
        self::assertSame(self::DR_A, $demand->ownDay->sourceHolderUserStableId);
        self::assertSame($source, $demand->ownDay->sourceDuty);
        self::assertNull($demand->ownDay->trigger);
    }

    public function testDrBRequiresAReinforcementWithTheMatchingTrigger(): void
    {
        $source = $this->sourceDuty('2027-01-05');
        $this->heldBy($source, self::DR_B);
        $duty = $this->conditionalDuty($source);

        $demand = $this->compute([$duty])->duties[0];

        self::assertTrue($demand->required);
        self::assertSame(DemandReason::TRIGGERED, $demand->reason);
        self::assertSame('trigger-b', $demand->ownDay->trigger?->triggerStableId);
        self::assertSame(Weekday::TUESDAY, $demand->ownDay->weekday);
        self::assertSame([$duty], $demand->triggeringDuties);
    }

    public function testDrCOnlyOnTheirWeekdays(): void
    {
        $tuesday = $this->sourceDuty('2027-01-05');
        $friday = $this->sourceDuty('2027-01-08');
        $this->heldBy($tuesday, self::DR_C);
        $this->heldBy($friday, self::DR_C);

        $onTuesday = $this->compute([$this->conditionalDuty($tuesday)])->duties[0];
        $onFriday = $this->compute([$this->conditionalDuty($friday)])->duties[0];

        self::assertFalse($onTuesday->required);
        self::assertSame(DemandReason::WEEKDAY_NOT_TRIGGERED, $onTuesday->reason);
        self::assertSame('trigger-c', $onTuesday->ownDay->trigger?->triggerStableId, 'The trigger that exists, but not for this weekday.');
        self::assertTrue($onFriday->required);
        self::assertSame(DemandReason::TRIGGERED, $onFriday->reason);
    }

    public function testAnUnassignedSourceIsExplicitlyNotRequired(): void
    {
        $source = $this->sourceDuty('2027-01-08');
        $this->heldBy($source, null);

        $demand = $this->compute([$this->conditionalDuty($source)])->duties[0];

        self::assertFalse($demand->required);
        self::assertSame(DemandReason::SOURCE_UNASSIGNED, $demand->reason);
        self::assertNull($demand->ownDay->sourceHolderUserStableId);
    }

    public function testASourceLineNeverGeneratedIsExplicitlyNotRequired(): void
    {
        $demand = $this->compute([$this->conditionalDuty($this->sourceDuty('2027-01-08'))])->duties[0];

        self::assertFalse($demand->required);
        self::assertSame(DemandReason::SOURCE_LINE_NOT_GENERATED, $demand->reason);
    }

    public function testRulesThatAreNotConditionalNeverTrigger(): void
    {
        $source = $this->sourceDuty('2027-01-08');
        $this->heldBy($source, self::DR_B);

        $demand = $this->compute([$this->conditionalDuty($source)], DemandRules::independent())->duties[0];

        self::assertFalse($demand->required);
        self::assertSame(DemandReason::LINE_NOT_CONDITIONAL, $demand->reason);
    }

    // --- blocks: atomic ----------------------------------------------------------------------------

    public function testOneTriggeredDayRequiresTheWholeBlockAndSaysWhichDay(): void
    {
        $friday = $this->sourceDuty('2027-01-08');
        $saturday = $this->sourceDuty('2027-01-09');
        $sunday = $this->sourceDuty('2027-01-10');
        $this->heldBy($friday, self::DR_A);
        $this->heldBy($saturday, self::DR_C);
        $this->heldBy($sunday, self::DR_A);
        [$fri, $sat, $sun] = $this->conditionalBlock([$friday, $saturday, $sunday]);

        $demand = $this->compute([$sun, $fri, $sat]);

        self::assertTrue($demand->required);
        self::assertSame([$sat], $demand->triggeringDuties);
        self::assertSame([$fri, $sat, $sun], array_map(static fn ($d) => $d->duty, $demand->duties), 'Always in date order.');
        foreach ($demand->duties as $dutyDemand) {
            self::assertTrue($dutyDemand->required, 'Every day of the block is required.');
            self::assertSame([$sat], $dutyDemand->triggeringDuties);
        }
        self::assertSame(DemandReason::TRIGGERED_BY_BLOCK, $demand->forDuty($fri)->reason);
        self::assertSame(DemandReason::HOLDER_HAS_NO_TRIGGER, $demand->forDuty($fri)->ownDay->reason, 'Its own day stays explained.');
        self::assertSame(DemandReason::TRIGGERED, $demand->forDuty($sat)->reason);
        self::assertSame(DemandReason::TRIGGERED_BY_BLOCK, $demand->forDuty($sun)->reason);
    }

    public function testABlockWithNoTriggeredDayIsNotRequiredAndEachDayKeepsItsReason(): void
    {
        $friday = $this->sourceDuty('2027-01-08');
        $saturday = $this->sourceDuty('2027-01-09');
        $this->heldBy($friday, self::DR_A);
        $this->heldBy($saturday, null);
        [$fri, $sat] = $this->conditionalBlock([$friday, $saturday]);

        $demand = $this->compute([$fri, $sat]);

        self::assertFalse($demand->required);
        self::assertSame([], $demand->triggeringDuties);
        self::assertSame(DemandReason::HOLDER_HAS_NO_TRIGGER, $demand->forDuty($fri)->reason);
        self::assertSame(DemandReason::SOURCE_UNASSIGNED, $demand->forDuty($sat)->reason);
    }

    public function testSeveralTriggeredDaysAreAllNamed(): void
    {
        $friday = $this->sourceDuty('2027-01-08');
        $saturday = $this->sourceDuty('2027-01-09');
        $this->heldBy($friday, self::DR_B);
        $this->heldBy($saturday, self::DR_C);
        [$fri, $sat] = $this->conditionalBlock([$friday, $saturday]);

        self::assertSame([$fri, $sat], $this->compute([$fri, $sat])->triggeringDuties);
    }

    public function testAUnitNeverMixesConditionalAndIntrinsicDuties(): void
    {
        $source = $this->sourceDuty('2027-01-08');

        $this->expectException(\LogicException::class);
        $this->compute([$source, $this->conditionalDuty($this->sourceDuty('2027-01-09'))]);
    }
}
