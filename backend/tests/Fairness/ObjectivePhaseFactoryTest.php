<?php

declare(strict_types=1);

namespace App\Tests\Fairness;

use App\Fairness\DefaultFairnessDimensionClassifier;
use App\Fairness\FairnessDimensionKey;
use App\Fairness\ObjectiveDirection;
use App\Fairness\ObjectivePhaseId;
use App\Fairness\OptimizationMode;
use App\Service\ObjectivePhaseFactory;
use PHPUnit\Framework\TestCase;

final class ObjectivePhaseFactoryTest extends TestCase
{
    private ObjectivePhaseFactory $factory;

    /**
     * @var list<FairnessDimensionKey>
     */
    private array $dimensions;

    protected function setUp(): void
    {
        $this->factory = new ObjectivePhaseFactory(new DefaultFairnessDimensionClassifier());
        $this->dimensions = [
            FairnessDimensionKey::totalDuties(),
            FairnessDimensionKey::weightedWorkload(),
            FairnessDimensionKey::friday(),
            FairnessDimensionKey::saturday(),
            FairnessDimensionKey::sunday(),
            FairnessDimensionKey::dutyType('duty-type-a'),
        ];
    }

    public function testGenerateReturnsExactlyEightPhases(): void
    {
        $phases = $this->factory->forMode(OptimizationMode::GENERATE, $this->dimensions);

        self::assertCount(8, $phases);
    }

    public function testExactOrderOfPhases(): void
    {
        $phases = $this->factory->forMode(OptimizationMode::GENERATE, $this->dimensions);

        self::assertSame([
            ObjectivePhaseId::MAX_DEVIATION_PRIMARY,
            ObjectivePhaseId::SUM_DEVIATION_PRIMARY,
            ObjectivePhaseId::MAX_DEVIATION_SECONDARY,
            ObjectivePhaseId::SUM_DEVIATION_SECONDARY,
            ObjectivePhaseId::NAMED_HOLIDAY_REPETITION_PENALTY,
            ObjectivePhaseId::SPACING_SCORE,
            ObjectivePhaseId::PREFERENCE_SATISFACTION,
            ObjectivePhaseId::DETERMINISTIC_TIE_BREAK,
        ], array_map(static fn ($phase) => $phase->id, $phases));
    }

    public function testBothPrimaryPhasesUseOnlyPrimaryDimensions(): void
    {
        $phases = $this->factory->forMode(OptimizationMode::GENERATE, $this->dimensions);

        // docs/decisions.md D086: no dimension is classified PRIMARY today
        // (WEEKEND_GROUPS/NAMED_HOLIDAY do not exist as FairnessDimensionType
        // cases) — both PRIMARY phases carry an empty dimension list, never
        // a SECONDARY dimension smuggled in.
        self::assertSame([], $phases[0]->dimensions);
        self::assertSame([], $phases[1]->dimensions);
    }

    public function testGenerateAcceptsAnEmptyPrimaryWithoutFabricatingData(): void
    {
        // docs/decisions.md D086: PRIMARY is empty for every input today
        // (WEEKEND_GROUPS/NAMED_HOLIDAY are not implemented dimensions).
        // The factory must still produce exactly 8 phases, with the two
        // PRIMARY phases present and structurally valid, never skipped and
        // never carrying an invented placeholder dimension.
        $phases = $this->factory->forMode(OptimizationMode::GENERATE, $this->dimensions);

        self::assertCount(8, $phases);
        self::assertSame(ObjectivePhaseId::MAX_DEVIATION_PRIMARY, $phases[0]->id);
        self::assertSame(ObjectivePhaseId::SUM_DEVIATION_PRIMARY, $phases[1]->id);
        self::assertSame([], $phases[0]->dimensions);
        self::assertSame([], $phases[1]->dimensions);
    }

    public function testBothSecondaryPhasesUseOnlySecondaryDimensions(): void
    {
        $phases = $this->factory->forMode(OptimizationMode::GENERATE, $this->dimensions);

        $maxSecondaryKeys = array_map(static fn (FairnessDimensionKey $k) => $k->toStringKey(), $phases[2]->dimensions);
        $sumSecondaryKeys = array_map(static fn (FairnessDimensionKey $k) => $k->toStringKey(), $phases[3]->dimensions);
        $expectedKeys = array_map(static fn (FairnessDimensionKey $k) => $k->toStringKey(), $this->dimensions);

        sort($maxSecondaryKeys);
        sort($sumSecondaryKeys);
        sort($expectedKeys);

        self::assertSame($expectedKeys, $maxSecondaryKeys, 'every supplied dimension is SECONDARY today');
        self::assertSame($expectedKeys, $sumSecondaryKeys);
    }

    public function testWeightedWorkloadAndTotalDutiesNeverBecomePrimaryByAccident(): void
    {
        $phases = $this->factory->forMode(OptimizationMode::GENERATE, $this->dimensions);

        $primaryKeys = array_map(static fn (FairnessDimensionKey $k) => $k->toStringKey(), $phases[0]->dimensions);

        self::assertNotContains(FairnessDimensionKey::weightedWorkload()->toStringKey(), $primaryKeys);
        self::assertNotContains(FairnessDimensionKey::totalDuties()->toStringKey(), $primaryKeys);
    }

    public function testDirectionIsCorrectForEachPhase(): void
    {
        $phases = $this->factory->forMode(OptimizationMode::GENERATE, $this->dimensions);

        self::assertSame(ObjectiveDirection::MINIMIZE, $phases[0]->direction, 'max deviation primary');
        self::assertSame(ObjectiveDirection::MINIMIZE, $phases[1]->direction, 'sum deviation primary');
        self::assertSame(ObjectiveDirection::MINIMIZE, $phases[2]->direction, 'max deviation secondary');
        self::assertSame(ObjectiveDirection::MINIMIZE, $phases[3]->direction, 'sum deviation secondary');
        self::assertSame(ObjectiveDirection::MINIMIZE, $phases[4]->direction, 'named holiday penalty');
        self::assertSame(ObjectiveDirection::MAXIMIZE, $phases[5]->direction, 'spacing score');
        self::assertSame(ObjectiveDirection::MAXIMIZE, $phases[6]->direction, 'preference satisfaction');
        self::assertSame(ObjectiveDirection::MINIMIZE, $phases[7]->direction, 'tie-break — convention, docs/decisions.md D088');
    }

    public function testTieBreakIsAlwaysLastPhase(): void
    {
        $phases = $this->factory->forMode(OptimizationMode::GENERATE, $this->dimensions);

        self::assertSame(ObjectivePhaseId::DETERMINISTIC_TIE_BREAK, end($phases)->id);
    }

    public function testPhaseOrderNeverDependsOnDimensionIterationOrder(): void
    {
        $reversed = array_reverse($this->dimensions);
        $shuffled = [
            $this->dimensions[3],
            $this->dimensions[0],
            $this->dimensions[5],
            $this->dimensions[1],
            $this->dimensions[4],
            $this->dimensions[2],
        ];

        $phasesA = $this->factory->forMode(OptimizationMode::GENERATE, $reversed);
        $phasesB = $this->factory->forMode(OptimizationMode::GENERATE, $shuffled);

        self::assertSame(
            array_map(static fn ($phase) => $phase->id, $phasesA),
            array_map(static fn ($phase) => $phase->id, $phasesB),
        );
    }

    public function testDeterministicAcrossRepeatedConstruction(): void
    {
        $first = $this->factory->forMode(OptimizationMode::GENERATE, $this->dimensions);
        $second = $this->factory->forMode(OptimizationMode::GENERATE, $this->dimensions);

        self::assertEquals($first, $second);
    }

    public function testRepairModeFailsExplicitly(): void
    {
        $this->expectException(\LogicException::class);

        $this->factory->forMode(OptimizationMode::REPAIR, $this->dimensions);
    }

    public function testSimulateModeFailsExplicitly(): void
    {
        $this->expectException(\LogicException::class);

        $this->factory->forMode(OptimizationMode::SIMULATE, $this->dimensions);
    }

    public function testPhaseDimensionsCannotBeMutatedFromOutside(): void
    {
        $phases = $this->factory->forMode(OptimizationMode::GENERATE, $this->dimensions);

        $this->expectException(\Error::class);

        // @phpstan-ignore-next-line — deliberately attempting an illegal write to prove readonly enforcement
        $phases[2]->dimensions[] = FairnessDimensionKey::totalDuties();
    }

    public function testExposedCollectionMutationNeverAffectsTheFactoryOutput(): void
    {
        $phases = $this->factory->forMode(OptimizationMode::GENERATE, $this->dimensions);
        $copy = $phases;
        $copy[] = $phases[0];

        self::assertCount(8, $this->factory->forMode(OptimizationMode::GENERATE, $this->dimensions));
        self::assertCount(9, $copy);
    }
}
