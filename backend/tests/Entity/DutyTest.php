<?php

declare(strict_types=1);

namespace App\Tests\Entity;

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
use PHPUnit\Framework\TestCase;

/**
 * docs/decisions.md D083: a DutyGroupInstance must never mix
 * REQUIRED and OPTIONAL Duties — it has to be classifiable as a single
 * requiredDutyUnit/optionalDutyUnit (docs/fairness.md). Through the real
 * application flow this can never happen
 * (DutyMaterializationService::materializeGroup() applies one demandType
 * to the whole group) — this test exercises the defense-in-depth guard in
 * Duty's own constructor directly, bypassing that service, the same way a
 * future/careless direct construction could.
 */
final class DutyTest extends TestCase
{
    private function newPlanningPeriod(): PlanningPeriod
    {
        $planning = new Planning(
            'Test Planning',
            new User('creator@example.com', 'Creator', 'User', 'hash'),
            new \DateTimeImmutable('2027-01-01'),
            new \DateTimeImmutable('2028-01-01'),
            'Europe/Brussels',
        );
        $team = new PlanningTeam($planning, 'Cardiology');
        $fairnessPeriod = new FairnessPeriod($team, '2027', new \DateTimeImmutable('2027-01-01'), new \DateTimeImmutable('2028-01-01'));

        return new PlanningPeriod($team, $fairnessPeriod, 'Jan-Apr', new \DateTimeImmutable('2027-01-01'), new \DateTimeImmutable('2027-05-01'));
    }

    private function newGroupInstance(PlanningPeriod $planningPeriod): DutyGroupInstance
    {
        $pattern = new DutyPattern($planningPeriod->getTeam(), 'WEEKEND', 'Week-end');

        return new DutyGroupInstance($planningPeriod, $pattern, new \DateTimeImmutable('2027-03-12'));
    }

    public function testAGroupsDutiesMayAllBeRequired(): void
    {
        $planningPeriod = $this->newPlanningPeriod();
        $group = $this->newGroupInstance($planningPeriod);
        $dutyType = new DutyType($planningPeriod->getTeam(), 'DAY', 'Garde de jour');

        new Duty($planningPeriod, $dutyType, new \DateTimeImmutable('2027-03-12 08:00', new \DateTimeZone('Europe/Brussels')), new \DateTimeImmutable('2027-03-12 20:00', new \DateTimeZone('Europe/Brussels')), 'Europe/Brussels', DutyDemandType::REQUIRED, groupInstance: $group);
        new Duty($planningPeriod, $dutyType, new \DateTimeImmutable('2027-03-13 08:00', new \DateTimeZone('Europe/Brussels')), new \DateTimeImmutable('2027-03-13 20:00', new \DateTimeZone('Europe/Brussels')), 'Europe/Brussels', DutyDemandType::REQUIRED, groupInstance: $group);

        self::assertCount(2, $group->getDuties());
    }

    public function testAGroupsDutiesMayAllBeOptional(): void
    {
        $planningPeriod = $this->newPlanningPeriod();
        $group = $this->newGroupInstance($planningPeriod);
        $dutyType = new DutyType($planningPeriod->getTeam(), 'DAY', 'Garde de jour');

        new Duty($planningPeriod, $dutyType, new \DateTimeImmutable('2027-03-12 08:00', new \DateTimeZone('Europe/Brussels')), new \DateTimeImmutable('2027-03-12 20:00', new \DateTimeZone('Europe/Brussels')), 'Europe/Brussels', DutyDemandType::OPTIONAL, groupInstance: $group);
        new Duty($planningPeriod, $dutyType, new \DateTimeImmutable('2027-03-13 08:00', new \DateTimeZone('Europe/Brussels')), new \DateTimeImmutable('2027-03-13 20:00', new \DateTimeZone('Europe/Brussels')), 'Europe/Brussels', DutyDemandType::OPTIONAL, groupInstance: $group);

        self::assertCount(2, $group->getDuties());
    }

    public function testAGroupCannotMixRequiredAndOptionalDuties(): void
    {
        $planningPeriod = $this->newPlanningPeriod();
        $group = $this->newGroupInstance($planningPeriod);
        $dutyType = new DutyType($planningPeriod->getTeam(), 'DAY', 'Garde de jour');

        new Duty($planningPeriod, $dutyType, new \DateTimeImmutable('2027-03-12 08:00', new \DateTimeZone('Europe/Brussels')), new \DateTimeImmutable('2027-03-12 20:00', new \DateTimeZone('Europe/Brussels')), 'Europe/Brussels', DutyDemandType::REQUIRED, groupInstance: $group);

        $this->expectException(\InvalidArgumentException::class);
        new Duty($planningPeriod, $dutyType, new \DateTimeImmutable('2027-03-13 08:00', new \DateTimeZone('Europe/Brussels')), new \DateTimeImmutable('2027-03-13 20:00', new \DateTimeZone('Europe/Brussels')), 'Europe/Brussels', DutyDemandType::OPTIONAL, groupInstance: $group);
    }
}
