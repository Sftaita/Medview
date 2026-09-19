<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Fairness\FairnessDimensionKey;
use App\Fairness\FairnessDimensionValues;
use App\Service\FairnessTargetService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pure unit tests against hand-built FairnessDimensionValues — no database,
 * no snapshot, exactly the adversarial target math
 * docs/allocation-algorithm.md §6 requires before any deviation
 * calculation is trusted.
 */
final class FairnessTargetServiceTest extends TestCase
{
    private FairnessTargetService $service;

    protected function setUp(): void
    {
        $this->service = new FairnessTargetService();
    }

    private function exposure(float $value): FairnessDimensionValues
    {
        return FairnessDimensionValues::empty()->withAdded(FairnessDimensionKey::totalDuties(), $value);
    }

    public function testEqualExposureProducesEqualTargets(): void
    {
        $requiredDemand = FairnessDimensionValues::empty()->withAdded(FairnessDimensionKey::totalDuties(), 10.0);
        $exposure = ['a' => $this->exposure(1.0), 'b' => $this->exposure(1.0)];

        [$targets] = $this->service->buildGrossTargets($requiredDemand, $exposure, [FairnessDimensionKey::totalDuties()]);

        self::assertSame(5.0, $targets['a']->get(FairnessDimensionKey::totalDuties()));
        self::assertSame(5.0, $targets['b']->get(FairnessDimensionKey::totalDuties()));
    }

    public function testProportionalExposureProducesProportionalTargets(): void
    {
        $requiredDemand = FairnessDimensionValues::empty()->withAdded(FairnessDimensionKey::totalDuties(), 30.0);
        $exposure = ['a' => $this->exposure(100.0), 'b' => $this->exposure(50.0)];

        [$targets] = $this->service->buildGrossTargets($requiredDemand, $exposure, [FairnessDimensionKey::totalDuties()]);

        self::assertEqualsWithDelta(20.0, $targets['a']->get(FairnessDimensionKey::totalDuties()), 1e-9);
        self::assertEqualsWithDelta(10.0, $targets['b']->get(FairnessDimensionKey::totalDuties()), 1e-9);
    }

    public function testZeroIndividualExposureProducesAZeroTargetNeverADeficit(): void
    {
        $requiredDemand = FairnessDimensionValues::empty()->withAdded(FairnessDimensionKey::totalDuties(), 10.0);
        $exposure = ['a' => $this->exposure(1.0), 'b' => $this->exposure(0.0)];

        [$targets] = $this->service->buildGrossTargets($requiredDemand, $exposure, [FairnessDimensionKey::totalDuties()]);

        self::assertSame(0.0, $targets['b']->get(FairnessDimensionKey::totalDuties()));
        self::assertSame(10.0, $targets['a']->get(FairnessDimensionKey::totalDuties()));
    }

    public function testTotalExposureZeroMakesTheDimensionNotApplicableNeverADivisionByZero(): void
    {
        $requiredDemand = FairnessDimensionValues::empty()->withAdded(FairnessDimensionKey::totalDuties(), 10.0);
        $exposure = ['a' => $this->exposure(0.0), 'b' => $this->exposure(0.0)];

        [$targets, $applicableDimensions] = $this->service->buildGrossTargets($requiredDemand, $exposure, [FairnessDimensionKey::totalDuties()]);

        self::assertSame([], $applicableDimensions);
        self::assertSame(0.0, $targets['a']->get(FairnessDimensionKey::totalDuties()));
        self::assertSame(0.0, $targets['b']->get(FairnessDimensionKey::totalDuties()));
    }

    public function testTargetsAreFractionalNeverRounded(): void
    {
        $requiredDemand = FairnessDimensionValues::empty()->withAdded(FairnessDimensionKey::totalDuties(), 1.0);
        $exposure = ['a' => $this->exposure(1.0), 'b' => $this->exposure(2.0)];

        [$targets] = $this->service->buildGrossTargets($requiredDemand, $exposure, [FairnessDimensionKey::totalDuties()]);

        self::assertEqualsWithDelta(1 / 3, $targets['a']->get(FairnessDimensionKey::totalDuties()), 1e-9);
        self::assertEqualsWithDelta(2 / 3, $targets['b']->get(FairnessDimensionKey::totalDuties()), 1e-9);
    }

    /**
     * @return list<array{0: float}>
     */
    public static function adversarialTargetProvider(): array
    {
        return [[0.0], [0.1], [0.5], [0.9], [1.0], [2.0], [10.0]];
    }

    #[DataProvider('adversarialTargetProvider')]
    public function testInvariantSumOfTargetsEqualsRequiredDemandForAdversarialValues(float $requiredDemandValue): void
    {
        $requiredDemand = FairnessDimensionValues::empty()->withAdded(FairnessDimensionKey::totalDuties(), $requiredDemandValue);
        $exposure = ['a' => $this->exposure(1.0), 'b' => $this->exposure(3.0), 'c' => $this->exposure(7.0)];

        [$targets] = $this->service->buildGrossTargets($requiredDemand, $exposure, [FairnessDimensionKey::totalDuties()]);

        $sum = $targets['a']->get(FairnessDimensionKey::totalDuties())
            + $targets['b']->get(FairnessDimensionKey::totalDuties())
            + $targets['c']->get(FairnessDimensionKey::totalDuties());

        self::assertEqualsWithDelta($requiredDemandValue, $sum, 1e-9);
    }

    public function testInvariantHoldsForAVeryAsymmetricDistribution(): void
    {
        $requiredDemand = FairnessDimensionValues::empty()->withAdded(FairnessDimensionKey::totalDuties(), 47.0);
        $exposure = [
            'a' => $this->exposure(1000.0),
            'b' => $this->exposure(1.0),
            'c' => $this->exposure(0.001),
        ];

        [$targets] = $this->service->buildGrossTargets($requiredDemand, $exposure, [FairnessDimensionKey::totalDuties()]);

        $sum = $targets['a']->get(FairnessDimensionKey::totalDuties())
            + $targets['b']->get(FairnessDimensionKey::totalDuties())
            + $targets['c']->get(FairnessDimensionKey::totalDuties());

        self::assertEqualsWithDelta(47.0, $sum, 1e-6);
        self::assertGreaterThan($targets['b']->get(FairnessDimensionKey::totalDuties()), $targets['a']->get(FairnessDimensionKey::totalDuties()));
    }

    public function testDiscretionaryTargetSubtractsStructurallyForcedLoadAndNeverGoesNegative(): void
    {
        $grossTargets = ['a' => $this->exposure(3.0), 'b' => $this->exposure(1.0)];
        // 'a' has more forced load than its gross target -> clamped to 0, never negative.
        $forcedLoad = ['a' => $this->exposure(5.0), 'b' => $this->exposure(0.4)];

        $discretionary = $this->service->buildDiscretionaryTargets($grossTargets, $forcedLoad, [FairnessDimensionKey::totalDuties()]);

        self::assertSame(0.0, $discretionary['a']->get(FairnessDimensionKey::totalDuties()), 'docs/decisions.md D085: max(0, grossTarget - structurallyForcedLoad)');
        self::assertEqualsWithDelta(0.6, $discretionary['b']->get(FairnessDimensionKey::totalDuties()), 1e-9);
    }

    public function testDiscretionaryTargetWithNoForcedLoadEqualsGrossTarget(): void
    {
        $grossTargets = ['a' => $this->exposure(3.0)];

        $discretionary = $this->service->buildDiscretionaryTargets($grossTargets, [], [FairnessDimensionKey::totalDuties()]);

        self::assertSame(3.0, $discretionary['a']->get(FairnessDimensionKey::totalDuties()));
    }
}
