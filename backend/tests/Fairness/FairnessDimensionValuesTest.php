<?php

declare(strict_types=1);

namespace App\Tests\Fairness;

use App\Fairness\FairnessDimensionKey;
use App\Fairness\FairnessDimensionValues;
use PHPUnit\Framework\TestCase;

final class FairnessDimensionValuesTest extends TestCase
{
    public function testGetDefaultsToZeroForAnAbsentKey(): void
    {
        $values = FairnessDimensionValues::empty();

        self::assertSame(0.0, $values->get(FairnessDimensionKey::totalDuties()));
    }

    public function testWithAddedNeverMutatesTheOriginalInstance(): void
    {
        $original = FairnessDimensionValues::empty();
        $updated = $original->withAdded(FairnessDimensionKey::friday(), 1.0);

        self::assertSame(0.0, $original->get(FairnessDimensionKey::friday()), 'withAdded must return a new instance, never mutate $this');
        self::assertSame(1.0, $updated->get(FairnessDimensionKey::friday()));
    }

    public function testWithAddedAccumulatesOnTopOfAnExistingValue(): void
    {
        $values = FairnessDimensionValues::empty()
            ->withAdded(FairnessDimensionKey::totalDuties(), 1.0)
            ->withAdded(FairnessDimensionKey::totalDuties(), 2.5);

        self::assertSame(3.5, $values->get(FairnessDimensionKey::totalDuties()));
    }

    public function testPlusSumsEveryKeyFromBothOperands(): void
    {
        $a = FairnessDimensionValues::empty()
            ->withAdded(FairnessDimensionKey::totalDuties(), 1.0)
            ->withAdded(FairnessDimensionKey::friday(), 1.0);
        $b = FairnessDimensionValues::empty()
            ->withAdded(FairnessDimensionKey::totalDuties(), 2.0)
            ->withAdded(FairnessDimensionKey::saturday(), 1.0);

        $sum = $a->plus($b);

        self::assertSame(3.0, $sum->get(FairnessDimensionKey::totalDuties()));
        self::assertSame(1.0, $sum->get(FairnessDimensionKey::friday()));
        self::assertSame(1.0, $sum->get(FairnessDimensionKey::saturday()));
        self::assertSame(0.0, $sum->get(FairnessDimensionKey::sunday()));
    }

    public function testScaledByMultipliesEveryValue(): void
    {
        $values = FairnessDimensionValues::empty()
            ->withAdded(FairnessDimensionKey::totalDuties(), 1.0)
            ->withAdded(FairnessDimensionKey::weightedWorkload(), 1.5)
            ->scaledBy(0.5);

        self::assertSame(0.5, $values->get(FairnessDimensionKey::totalDuties()));
        self::assertSame(0.75, $values->get(FairnessDimensionKey::weightedWorkload()));
    }

    public function testDutyTypeDimensionsAreKeyedByStableIdNeverConfusedWithAnotherDutyType(): void
    {
        $values = FairnessDimensionValues::empty()
            ->withAdded(FairnessDimensionKey::dutyType('type-a'), 1.0)
            ->withAdded(FairnessDimensionKey::dutyType('type-b'), 5.0);

        self::assertSame(1.0, $values->get(FairnessDimensionKey::dutyType('type-a')));
        self::assertSame(5.0, $values->get(FairnessDimensionKey::dutyType('type-b')));
    }
}
