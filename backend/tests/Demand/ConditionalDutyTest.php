<?php

declare(strict_types=1);

namespace App\Tests\Demand;

use App\Entity\Duty;
use App\Entity\DutyDemandType;
use PHPUnit\Framework\TestCase;

/**
 * What the Duty entity itself guarantees about a conditional duty and its
 * coverage source (docs/decisions.md D163) — in memory, no database.
 */
final class ConditionalDutyTest extends TestCase
{
    use InMemoryConditionalFixtures;

    protected function setUp(): void
    {
        $this->buildTwoLines();
    }

    public function testAConditionalDutyKeepsItsExplicitCoverageSource(): void
    {
        $source = $this->sourceDuty('2027-01-08');
        $duty = $this->conditionalDuty($source);

        self::assertSame(DutyDemandType::CONDITIONAL, $duty->getDemandType());
        self::assertSame($source, $duty->getCoverageSource());
        self::assertTrue($duty->isConditional());
    }

    public function testAConditionalDutyRequiresACoverageSource(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Duty($this->targetPeriod, $this->targetType, $this->instant('2027-01-08'), $this->instant('2027-01-09'), 'Europe/Brussels', DutyDemandType::CONDITIONAL);
    }

    public function testAnIntrinsicDutyNeverHasACoverageSource(): void
    {
        $source = $this->sourceDuty('2027-01-08');

        $this->expectException(\InvalidArgumentException::class);
        new Duty($this->targetPeriod, $this->targetType, $this->instant('2027-01-08'), $this->instant('2027-01-09'), 'Europe/Brussels', DutyDemandType::REQUIRED, coverageSource: $source);
    }

    public function testACoverageSourceOfTheSameLineIsRefused(): void
    {
        $sameLine = new Duty($this->targetPeriod, $this->targetType, $this->instant('2027-01-08'), $this->instant('2027-01-09'), 'Europe/Brussels');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('another line');
        new Duty($this->targetPeriod, $this->targetType, $this->instant('2027-01-08'), $this->instant('2027-01-09'), 'Europe/Brussels', DutyDemandType::CONDITIONAL, coverageSource: $sameLine);
    }

    public function testACoverageSourceOfAnotherPlanningIsRefused(): void
    {
        $otherPlanning = new \App\Entity\Planning('Autre', new \App\Entity\User('o@example.com', 'O', 'O', 'x'), new \DateTimeImmutable('2027-01-01'), new \DateTimeImmutable('2027-05-01'), 'Europe/Brussels');
        [$otherPeriod, $otherType] = $this->lineOf($otherPlanning, 'Ailleurs');
        $foreign = new Duty($otherPeriod, $otherType, $this->instant('2027-01-08'), $this->instant('2027-01-09'), 'Europe/Brussels');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('same Planning');
        $this->conditionalDuty($foreign);
    }

    public function testAConditionalCoverageSourceIsRefusedDepthOne(): void
    {
        $conditional = $this->conditionalDuty($this->sourceDuty('2027-01-08'));
        [$thirdPeriod, $thirdType] = $this->lineOf($this->planning, 'Troisième');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('never be conditional itself');
        new Duty($thirdPeriod, $thirdType, $this->instant('2027-01-08'), $this->instant('2027-01-09'), 'Europe/Brussels', DutyDemandType::CONDITIONAL, coverageSource: $conditional);
    }

    public function testACoverageSourceOnAnotherDayIsRefused(): void
    {
        $friday = $this->sourceDuty('2027-01-08');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('same calendar day');
        new Duty($this->targetPeriod, $this->targetType, $this->instant('2027-01-09'), $this->instant('2027-01-10'), 'Europe/Brussels', DutyDemandType::CONDITIONAL, coverageSource: $friday);
    }

    public function testAConditionalDutyHasNoIntrinsicAnswerToIsRequired(): void
    {
        $this->expectException(\LogicException::class);
        $this->conditionalDuty($this->sourceDuty('2027-01-08'))->isRequired();
    }

    public function testRequiredAndOptionalDutiesAnswerIsRequiredAsBefore(): void
    {
        self::assertTrue($this->sourceDuty('2027-01-08')->isRequired());
        self::assertFalse($this->sourceDuty('2027-01-09', DutyDemandType::OPTIONAL)->isRequired());
        self::assertNull($this->sourceDuty('2027-01-10')->getCoverageSource());
    }

    public function testTheDemandTypeOfADutyCanNeverBeChanged(): void
    {
        $methods = array_map(static fn (\ReflectionMethod $m): string => $m->getName(), (new \ReflectionClass(Duty::class))->getMethods(\ReflectionMethod::IS_PUBLIC));

        self::assertEmpty(array_filter($methods, static fn (string $m): bool => 1 === preg_match('/^(set|change|mark).*(Demand|Coverage)/i', $m)), 'No way to rewrite a duty\'s demand kind or coverage source.');
    }
}
