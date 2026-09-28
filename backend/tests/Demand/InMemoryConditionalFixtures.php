<?php

declare(strict_types=1);

namespace App\Tests\Demand;

use App\Entity\Duty;
use App\Entity\DutyDemandType;
use App\Entity\DutyGroupInstance;
use App\Entity\DutyPattern;
use App\Entity\DutyType;
use App\Entity\FairnessPeriod;
use App\Entity\Planning;
use App\Entity\PlanningPeriod;
use App\Entity\PlanningTeam;
use App\Entity\User;

/**
 * Entities built in memory, never persisted — enough for the pure demand
 * rule (docs/decisions.md D163) and the Duty constructor's own guards.
 * Two lines of one Planning: a source line and a conditional line, both
 * over January–April 2027, Europe/Brussels.
 */
trait InMemoryConditionalFixtures
{
    private Planning $planning;
    private PlanningPeriod $sourcePeriod;
    private PlanningPeriod $targetPeriod;
    private DutyType $sourceType;
    private DutyType $targetType;

    private function buildTwoLines(): void
    {
        $this->planning = new Planning('Gardes', new User('creator@example.com', 'C', 'R', 'x'), new \DateTimeImmutable('2027-01-01'), new \DateTimeImmutable('2027-05-01'), 'Europe/Brussels');
        [$this->sourcePeriod, $this->sourceType] = $this->lineOf($this->planning, 'Principale');
        [$this->targetPeriod, $this->targetType] = $this->lineOf($this->planning, 'Renfort');
    }

    /**
     * @return array{0: PlanningPeriod, 1: DutyType}
     */
    private function lineOf(Planning $planning, string $name): array
    {
        $team = new PlanningTeam($planning, $name);
        $fairness = new FairnessPeriod($team, '2027', new \DateTimeImmutable('2027-01-01'), new \DateTimeImmutable('2028-01-01'));

        return [new PlanningPeriod($team, $fairness, $name, new \DateTimeImmutable('2027-01-01'), new \DateTimeImmutable('2027-05-01')), new DutyType($team, 'GARDE', 'Garde')];
    }

    private function instant(string $local): \DateTimeImmutable
    {
        return new \DateTimeImmutable($local, new \DateTimeZone('Europe/Brussels'));
    }

    /** A whole-day duty of the source line. */
    private function sourceDuty(string $day, DutyDemandType $type = DutyDemandType::REQUIRED): Duty
    {
        return new Duty($this->sourcePeriod, $this->sourceType, $this->instant($day), $this->instant($day)->modify('+1 day'), 'Europe/Brussels', $type);
    }

    /** A whole-day conditional duty of the target line, covering $source. */
    private function conditionalDuty(Duty $source, ?DutyGroupInstance $group = null): Duty
    {
        $day = $source->getLocalDate()->format('Y-m-d');

        return new Duty($this->targetPeriod, $this->targetType, $this->instant($day), $this->instant($day)->modify('+1 day'), 'Europe/Brussels', DutyDemandType::CONDITIONAL, groupInstance: $group, coverageSource: $source);
    }

    /**
     * A conditional block over the given days, each covering the matching source duty.
     *
     * @param list<Duty> $sources
     *
     * @return list<Duty>
     */
    private function conditionalBlock(array $sources): array
    {
        $pattern = new DutyPattern($this->targetPeriod->getTeam(), 'WE-'.bin2hex(random_bytes(3)), 'Week-end');
        $group = new DutyGroupInstance($this->targetPeriod, $pattern, $sources[0]->getLocalDate());

        return array_map(fn (Duty $source): Duty => $this->conditionalDuty($source, $group), $sources);
    }
}
