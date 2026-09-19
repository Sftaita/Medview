<?php

declare(strict_types=1);

namespace App\Tests\Solver;

use App\Fairness\FairnessDimensionType;
use App\Solver\CpSatScale;
use PHPUnit\Framework\TestCase;

final class CpSatScaleTest extends TestCase
{
    public function testScaleIsTenThousand(): void
    {
        self::assertSame(10_000, CpSatScale::SCALE);
    }

    public function testWeightedWorkloadSmallestUnitMatchesRealColumnPrecision(): void
    {
        // src/Entity/DutyType.php: workloadValue is decimal(6,2) — the real
        // smallest representable step is 0.01, not an arbitrary choice.
        self::assertSame(0.01, CpSatScale::smallestUnit(FairnessDimensionType::WEIGHTED_WORKLOAD));
    }

    public function testCountDimensionsHaveASmallestUnitOfOneWholeDuty(): void
    {
        self::assertSame(1.0, CpSatScale::smallestUnit(FairnessDimensionType::TOTAL_DUTIES));
        self::assertSame(1.0, CpSatScale::smallestUnit(FairnessDimensionType::FRIDAY));
        self::assertSame(1.0, CpSatScale::smallestUnit(FairnessDimensionType::SATURDAY));
        self::assertSame(1.0, CpSatScale::smallestUnit(FairnessDimensionType::SUNDAY));
        self::assertSame(1.0, CpSatScale::smallestUnit(FairnessDimensionType::DUTY_TYPE));
    }

    public function testScaleExactlyRepresentsTheWorkloadValuePrecisionAsAnInteger(): void
    {
        $scaledSmallestUnit = CpSatScale::smallestUnit(FairnessDimensionType::WEIGHTED_WORKLOAD) * CpSatScale::SCALE;

        self::assertSame(100.0, $scaledSmallestUnit, 'SCALE * 0.01 must be an exact integer, never a rounding artifact');
    }
}
