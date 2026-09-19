<?php

declare(strict_types=1);

namespace App\Tests\Fairness;

use App\Fairness\DefaultFairnessDimensionClassifier;
use App\Fairness\FairnessDimensionKey;
use PHPUnit\Framework\TestCase;

/**
 * docs/decisions.md D086 — WEEKEND_GROUPS/NAMED_HOLIDAY are the real
 * spec default for PRIMARY (docs/allocation-algorithm.md §6) but are not
 * implemented as FairnessDimensionType cases (docs/fairness.md §3), so
 * PRIMARY must stay empty today — locked in as a regression test, not
 * merely a docblock claim.
 */
final class DefaultFairnessDimensionClassifierTest extends TestCase
{
    /**
     * @return list<FairnessDimensionKey> one representative key per
     *                                    currently-supported FairnessDimensionType case
     */
    private function allSupportedDimensions(): array
    {
        return [
            FairnessDimensionKey::totalDuties(),
            FairnessDimensionKey::weightedWorkload(),
            FairnessDimensionKey::friday(),
            FairnessDimensionKey::saturday(),
            FairnessDimensionKey::sunday(),
            FairnessDimensionKey::dutyType('some-duty-type'),
        ];
    }

    public function testPrimaryIsExactlyEmptyForTodaysSupportedDimensions(): void
    {
        $classifier = new DefaultFairnessDimensionClassifier();

        self::assertSame([], $classifier->primaryDimensions($this->allSupportedDimensions()));
    }

    public function testTotalDutiesIsNeverPrimary(): void
    {
        $classifier = new DefaultFairnessDimensionClassifier();

        $primary = $classifier->primaryDimensions([FairnessDimensionKey::totalDuties()]);

        self::assertSame([], $primary);
    }

    public function testWeightedWorkloadIsNeverPrimary(): void
    {
        $classifier = new DefaultFairnessDimensionClassifier();

        $primary = $classifier->primaryDimensions([FairnessDimensionKey::weightedWorkload()]);

        self::assertSame([], $primary);
    }

    public function testSecondaryContainsEveryInputDimension(): void
    {
        $classifier = new DefaultFairnessDimensionClassifier();

        $dimensions = [
            FairnessDimensionKey::totalDuties(),
            FairnessDimensionKey::weightedWorkload(),
            FairnessDimensionKey::friday(),
        ];

        $secondary = $classifier->secondaryDimensions($dimensions);

        self::assertCount(3, $secondary);
        foreach ($dimensions as $dimension) {
            self::assertTrue(
                array_reduce($secondary, static fn (bool $found, FairnessDimensionKey $s) => $found || $s->equals($dimension), false),
                sprintf('%s should be classified SECONDARY', $dimension->toStringKey()),
            );
        }
    }

    public function testEveryCurrentlySupportedDimensionIsClassifiedExactlyOnce(): void
    {
        $classifier = new DefaultFairnessDimensionClassifier();
        $dimensions = $this->allSupportedDimensions();

        $primary = $classifier->primaryDimensions($dimensions);
        $secondary = $classifier->secondaryDimensions($dimensions);

        $primaryKeys = array_map(static fn (FairnessDimensionKey $k) => $k->toStringKey(), $primary);
        $secondaryKeys = array_map(static fn (FairnessDimensionKey $k) => $k->toStringKey(), $secondary);
        $inputKeys = array_map(static fn (FairnessDimensionKey $k) => $k->toStringKey(), $dimensions);

        sort($secondaryKeys);
        sort($inputKeys);

        self::assertSame([], $primaryKeys, 'no supported dimension is PRIMARY today');
        self::assertSame($inputKeys, $secondaryKeys, 'every supported dimension is classified exactly once, as SECONDARY');
        self::assertCount(\count($dimensions), array_unique($secondaryKeys), 'no dimension is classified twice');
    }

    public function testPrimaryAndSecondaryAreDisjoint(): void
    {
        $classifier = new DefaultFairnessDimensionClassifier();
        $dimensions = $this->allSupportedDimensions();

        $primaryKeys = array_map(static fn (FairnessDimensionKey $k) => $k->toStringKey(), $classifier->primaryDimensions($dimensions));
        $secondaryKeys = array_map(static fn (FairnessDimensionKey $k) => $k->toStringKey(), $classifier->secondaryDimensions($dimensions));

        self::assertSame([], array_intersect($primaryKeys, $secondaryKeys), 'no dimension may belong to both PRIMARY and SECONDARY at once');
    }

    public function testNeverInventsADimensionNotInTheInput(): void
    {
        $classifier = new DefaultFairnessDimensionClassifier();

        $secondary = $classifier->secondaryDimensions([FairnessDimensionKey::friday()]);

        self::assertCount(1, $secondary);
        self::assertTrue($secondary[0]->equals(FairnessDimensionKey::friday()));
    }

    public function testPrimaryNeverInventsADimensionNotInTheInputEither(): void
    {
        $classifier = new DefaultFairnessDimensionClassifier();

        // Even with a full, realistic supported-dimension set, PRIMARY
        // must never fabricate WEEKEND_GROUPS/NAMED_HOLIDAY or anything
        // else absent from the input — it can only ever be a subset.
        $primary = $classifier->primaryDimensions($this->allSupportedDimensions());

        self::assertSame([], $primary);
    }
}
