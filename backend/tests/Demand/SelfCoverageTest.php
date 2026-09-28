<?php

declare(strict_types=1);

namespace App\Tests\Demand;

use App\Eligibility\CommitmentInterval;
use App\Eligibility\ExclusionReason;
use App\Entity\Duty;
use App\Entity\DutyDemandType;
use App\Entity\RestPolicyOptions;
use App\Service\PersonCommitmentChecker;
use App\Service\RestGapCalculator;
use PHPUnit\Framework\TestCase;

/**
 * "Nobody can be their own reinforcement" (docs/decisions.md D163) is a
 * business rule, not a timing one: it holds even when the reinforcement
 * and its source duty do not overlap at all.
 */
final class SelfCoverageTest extends TestCase
{
    use InMemoryConditionalFixtures;

    protected function setUp(): void
    {
        $this->buildTwoLines();
    }

    private function asCommitment(Duty $duty, bool $sameGeneration = false): CommitmentInterval
    {
        return new CommitmentInterval($duty->getStartsAt(), $duty->getEndsAt(), $sameGeneration, null, null, (string) $duty->getStableId(), 'source-line');
    }

    public function testHoldingTheCoverageSourceIsSelfCoverageEvenWithoutAnyOverlap(): void
    {
        // Main-line duty 08:00–12:00; its reinforcement 14:00–20:00 the same day: no overlap, and no rest rule at all.
        $source = new Duty($this->sourcePeriod, $this->sourceType, $this->instant('2027-01-08 08:00'), $this->instant('2027-01-08 12:00'), 'Europe/Brussels');
        $reinforcement = new Duty($this->targetPeriod, $this->targetType, $this->instant('2027-01-08 14:00'), $this->instant('2027-01-08 20:00'), 'Europe/Brussels', DutyDemandType::CONDITIONAL, coverageSource: $source);
        $checker = new PersonCommitmentChecker(new RestGapCalculator());

        $violation = $checker->firstViolation([$reinforcement], [$this->asCommitment($source)], RestPolicyOptions::none());

        self::assertSame(ExclusionReason::SELF_COVERAGE, $violation?->reason);
        self::assertSame((string) $source->getStableId(), $violation->commitment->dutyStableId);
    }

    public function testSelfCoverageWinsOverAnOverlap(): void
    {
        $source = $this->sourceDuty('2027-01-09');
        $checker = new PersonCommitmentChecker(new RestGapCalculator());

        self::assertSame(ExclusionReason::SELF_COVERAGE, $checker->firstViolation([$this->conditionalDuty($source)], [$this->asCommitment($source)], RestPolicyOptions::none())?->reason);
    }

    public function testAnyDayOfABlockWhoseSourceThePersonHoldsIsSelfCoverage(): void
    {
        $friday = $this->sourceDuty('2027-01-08');
        $saturday = $this->sourceDuty('2027-01-09');
        $block = $this->conditionalBlock([$friday, $saturday]);
        $checker = new PersonCommitmentChecker(new RestGapCalculator());

        self::assertSame(ExclusionReason::SELF_COVERAGE, $checker->firstViolation($block, [$this->asCommitment($saturday)], RestPolicyOptions::none())?->reason);
    }

    public function testHoldingAnotherSourceDutyIsNotSelfCoverage(): void
    {
        // The person holds the main line on Tuesday; the reinforcement is on Friday: nothing to refuse.
        $tuesday = $this->sourceDuty('2027-01-05');
        $checker = new PersonCommitmentChecker(new RestGapCalculator());

        self::assertNull($checker->firstViolation([$this->conditionalDuty($this->sourceDuty('2027-01-08'))], [$this->asCommitment($tuesday)], RestPolicyOptions::none()));
    }

    public function testAnIntrinsicDutyNeverRaisesSelfCoverage(): void
    {
        $friday = $this->sourceDuty('2027-01-08');
        $checker = new PersonCommitmentChecker(new RestGapCalculator());
        $sameDayOtherLine = new Duty($this->targetPeriod, $this->targetType, $this->instant('2027-01-08'), $this->instant('2027-01-09'), 'Europe/Brussels');

        self::assertSame(ExclusionReason::CROSS_LINE_CONFLICT, $checker->firstViolation([$sameDayOtherLine], [$this->asCommitment($friday)], RestPolicyOptions::none())?->reason);
    }
}
