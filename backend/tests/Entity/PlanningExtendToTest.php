<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\FairnessPeriod;
use App\Entity\Planning;
use App\Entity\PlanningPeriod;
use App\Entity\PlanningPeriodStatus;
use App\Entity\PlanningTeam;
use App\Entity\User;
use App\Exception\PlanningPeriodLockedException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The in-place growth of a planning and its periods (docs/decisions.md D122):
 * only ever a superset of the current range, and never on a validated or
 * published period. Pure entity tests, no kernel.
 */
final class PlanningExtendToTest extends TestCase
{
    private function d(string $date): \DateTimeImmutable
    {
        return new \DateTimeImmutable($date);
    }

    private function planning(): Planning
    {
        return new Planning('P', new User('a@example.com', 'Ada', 'Lovelace', 'hash'), $this->d('2027-01-01'), $this->d('2027-05-01'), 'Europe/Brussels');
    }

    private function period(Planning $planning): PlanningPeriod
    {
        $team = new PlanningTeam($planning, 'T');
        $fairness = new FairnessPeriod($team, 'F', $this->d('2027-01-01'), $this->d('2027-05-01'));

        return new PlanningPeriod($team, $fairness, 'PP', $this->d('2027-01-01'), $this->d('2027-05-01'));
    }

    public function testAPlanningGrowsOnBothSides(): void
    {
        $planning = $this->planning();

        $planning->extendTo($this->d('2026-12-01'), $this->d('2027-08-01'));

        self::assertSame('2026-12-01', $planning->getStartsAt()->format('Y-m-d'));
        self::assertSame('2027-08-01', $planning->getEndsAt()->format('Y-m-d'));
    }

    public function testAPlanningNeverShrinks(): void
    {
        $planning = $this->planning();

        $this->expectException(\InvalidArgumentException::class);

        $planning->extendTo($this->d('2027-01-01'), $this->d('2027-04-01'));
    }

    public function testAFairnessPeriodNeverShrinks(): void
    {
        $fairness = new FairnessPeriod(new PlanningTeam($this->planning(), 'T'), 'F', $this->d('2027-01-01'), $this->d('2027-05-01'));

        $this->expectException(\InvalidArgumentException::class);

        $fairness->extendTo($this->d('2027-02-01'), $this->d('2027-05-01'));
    }

    public function testAPlanningPeriodMustBeWithinItsFairnessPeriodSoTheFairnessPeriodGrowsFirst(): void
    {
        $period = $this->period($this->planning());

        try {
            $period->extendTo($this->d('2027-01-01'), $this->d('2027-08-01'));
            self::fail('expected an InvalidArgumentException');
        } catch (\InvalidArgumentException) {
        }

        $period->getFairnessPeriod()->extendTo($this->d('2027-01-01'), $this->d('2027-08-01'));
        $period->extendTo($this->d('2027-01-01'), $this->d('2027-08-01'));

        self::assertSame('2027-08-01', $period->getEndsAt()->format('Y-m-d'));
    }

    /**
     * @return iterable<string, array{PlanningPeriodStatus}>
     */
    public static function lockedStatuses(): iterable
    {
        yield 'validated' => [PlanningPeriodStatus::VALIDATED];
        yield 'published' => [PlanningPeriodStatus::PUBLISHED];
        yield 'archived' => [PlanningPeriodStatus::ARCHIVED];
    }

    #[DataProvider('lockedStatuses')]
    public function testAValidatedPublishedOrArchivedPeriodIsNeverExtended(PlanningPeriodStatus $status): void
    {
        $period = $this->period($this->planning());
        $period->getFairnessPeriod()->extendTo($this->d('2027-01-01'), $this->d('2027-08-01'));
        $path = [PlanningPeriodStatus::GENERATED, PlanningPeriodStatus::VALIDATED, PlanningPeriodStatus::PUBLISHED, PlanningPeriodStatus::ARCHIVED];
        foreach ($path as $step) {
            $period->transitionTo($step);
            if ($step === $status) {
                break;
            }
        }

        $this->expectException(PlanningPeriodLockedException::class);

        $period->extendTo($this->d('2027-01-01'), $this->d('2027-08-01'));
    }
}
