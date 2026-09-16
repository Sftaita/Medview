<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\DutyPattern;
use App\Entity\DutyType;
use App\Exception\DutyPatternMismatchException;
use App\Service\DutyMaterializationService;
use App\Tests\PlanningDomainTestHelpers;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DutyMaterializationServiceTest extends KernelTestCase
{
    use PlanningDomainTestHelpers;

    public function testDutyTypeCodeMustBeUniquePerTeam(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $team = $this->createTeam($em);
        $em->persist(new DutyType($team, 'NIGHT', 'Garde de nuit'));
        $em->flush();

        $em->persist(new DutyType($team, 'NIGHT', 'Autre garde de nuit'));
        $this->expectException(UniqueConstraintViolationException::class);
        $em->flush();
    }

    public function testTheSameCodeIsAllowedAcrossDifferentTeams(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $teamA = $this->createTeam($em, 'Cardiology');
        $teamB = $this->createTeam($em, 'Radiology');

        $em->persist(new DutyType($teamA, 'NIGHT', 'Garde de nuit'));
        $em->persist(new DutyType($teamB, 'NIGHT', 'Garde de nuit'));
        $em->flush();

        $this->addToAssertionCount(1); // no exception: reaching here is the assertion
    }

    public function testWorkloadValueRoundTripsExactly(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $team = $this->createTeam($em);
        $dutyType = new DutyType($team, 'NIGHT', 'Garde de nuit', 1.5);
        $em->persist($dutyType);
        $em->flush();
        $id = $dutyType->getId();
        $em->clear();

        $reloaded = $em->find(DutyType::class, $id);
        self::assertSame(1.5, $reloaded->getWorkloadValue());
    }

    public function testDutyTypeRejectsNonPositiveWorkload(): void
    {
        self::bootKernel();
        $team = new \App\Entity\Team('Cardiology', 'cardiology');

        $this->expectException(\InvalidArgumentException::class);
        new DutyType($team, 'NIGHT', 'Garde de nuit', 0.0);
    }

    public function testPatternRejectsADuplicateDayOffset(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $team = $this->createTeam($em);
        $dutyType = new DutyType($team, 'DAY', 'Garde de jour');
        $em->persist($dutyType);

        $pattern = new DutyPattern($team, 'WEEKEND_FULL', 'Week-end complet');
        $pattern->addComponent(0, $dutyType);

        $this->expectException(\InvalidArgumentException::class);
        $pattern->addComponent(0, $dutyType);
    }

    public function testPatternRejectsAComponentFromAnotherTeam(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $team = $this->createTeam($em, 'Cardiology');
        $otherTeam = $this->createTeam($em, 'Radiology');
        $otherTeamDutyType = new DutyType($otherTeam, 'DAY', 'Garde de jour');
        $em->persist($otherTeamDutyType);
        $em->flush();

        $pattern = new DutyPattern($team, 'WEEKEND_FULL', 'Week-end complet');

        $this->expectException(\InvalidArgumentException::class);
        $pattern->addComponent(0, $otherTeamDutyType);
    }

    public function testMaterializingAGroupCreatesExactlyThePatternsComponentsAsAtomicUnit(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $service = self::getContainer()->get(DutyMaterializationService::class);

        $team = $this->createTeam($em);
        $planningPeriod = $this->createPlanningPeriod($em, $team);
        $friday = new DutyType($team, 'FRIDAY', 'Vendredi');
        $saturday = new DutyType($team, 'SATURDAY', 'Samedi');
        $sunday = new DutyType($team, 'SUNDAY', 'Dimanche');
        $em->persist($friday);
        $em->persist($saturday);
        $em->persist($sunday);

        $pattern = new DutyPattern($team, 'WEEKEND_FULL', 'Week-end complet');
        $pattern->addComponent(0, $friday);
        $pattern->addComponent(1, $saturday);
        $pattern->addComponent(2, $sunday);
        $em->persist($pattern);
        $em->flush();

        $anchor = $this->date('2027-03-12'); // a Friday
        $groupInstance = $service->materializeGroup($planningPeriod, $pattern, $anchor, [
            0 => [$this->localTime('2027-03-12 08:00'), $this->localTime('2027-03-13 08:00')],
            1 => [$this->localTime('2027-03-13 08:00'), $this->localTime('2027-03-14 08:00')],
            2 => [$this->localTime('2027-03-14 08:00'), $this->localTime('2027-03-15 08:00')],
        ]);

        self::assertCount(3, $groupInstance->getDuties());
        foreach ($groupInstance->getDuties() as $duty) {
            self::assertSame($groupInstance, $duty->getGroupInstance());
            self::assertSame($planningPeriod, $duty->getPlanningPeriod());
        }
    }

    public function testMaterializingAGroupRejectsAMismatchedComponentSet(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $service = self::getContainer()->get(DutyMaterializationService::class);

        $team = $this->createTeam($em);
        $planningPeriod = $this->createPlanningPeriod($em, $team);
        $saturday = new DutyType($team, 'SATURDAY', 'Samedi');
        $sunday = new DutyType($team, 'SUNDAY', 'Dimanche');
        $em->persist($saturday);
        $em->persist($sunday);

        $pattern = new DutyPattern($team, 'WEEKEND', 'Week-end');
        $pattern->addComponent(0, $saturday);
        $pattern->addComponent(1, $sunday);
        $em->persist($pattern);
        $em->flush();

        $this->expectException(DutyPatternMismatchException::class);
        // Only provides offset 0, missing offset 1 required by the pattern.
        $service->materializeGroup($planningPeriod, $pattern, $this->date('2027-03-13'), [
            0 => [$this->localTime('2027-03-13 08:00'), $this->localTime('2027-03-14 08:00')],
        ]);
    }

    public function testStandaloneDutyHasAStableIdAndCorrectLocalDateForANightShiftCrossingMidnight(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $service = self::getContainer()->get(DutyMaterializationService::class);

        $team = $this->createTeam($em);
        $planningPeriod = $this->createPlanningPeriod($em, $team);
        $night = new DutyType($team, 'NIGHT', 'Garde de nuit');
        $em->persist($night);
        $em->flush();

        $duty = $service->createStandaloneDuty(
            $planningPeriod,
            $night,
            $this->localTime('2027-03-13 20:00'),
            $this->localTime('2027-03-14 06:00'),
        );

        self::assertNotNull($duty->getId());
        self::assertInstanceOf(\Symfony\Component\Uid\Uuid::class, $duty->getStableId());
        self::assertEquals($this->date('2027-03-13'), $duty->getLocalDate(), 'a Saturday-night duty ending Sunday morning still counts as Saturday');
        self::assertSame('Europe/Brussels', $duty->getTimezone());
    }

    public function testDstSpringForwardDoesNotDistortSpacingArithmetic(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $service = self::getContainer()->get(DutyMaterializationService::class);

        $team = $this->createTeam($em);
        $planningPeriod = $this->createPlanningPeriod($em, $team);
        $night = new DutyType($team, 'NIGHT', 'Garde de nuit');
        $em->persist($night);
        $em->flush();

        // Europe/Brussels DST 2027 starts Sunday 28 March at 02:00 -> 03:00 (a 23-hour local day).
        $duty = $service->createStandaloneDuty(
            $planningPeriod,
            $night,
            $this->localTime('2027-03-27 20:00'),
            $this->localTime('2027-03-28 08:00'),
        );

        // 12 wall-clock hours minus the DST hour skipped = 11 real elapsed hours.
        $elapsedSeconds = $duty->getEndsAt()->getTimestamp() - $duty->getStartsAt()->getTimestamp();
        self::assertSame(11 * 3600, $elapsedSeconds, 'the absolute instant must reflect the real DST-shortened duration');
    }

    public function testOverlappingDutiesAreDetectedByAbsoluteInstant(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $service = self::getContainer()->get(DutyMaterializationService::class);

        $team = $this->createTeam($em);
        $planningPeriod = $this->createPlanningPeriod($em, $team);
        $dutyType = new DutyType($team, 'DAY', 'Garde de jour');
        $em->persist($dutyType);
        $em->flush();

        $first = $service->createStandaloneDuty($planningPeriod, $dutyType, $this->localTime('2027-03-13 08:00'), $this->localTime('2027-03-13 20:00'));
        $second = $service->createStandaloneDuty($planningPeriod, $dutyType, $this->localTime('2027-03-13 18:00'), $this->localTime('2027-03-14 08:00'));
        $third = $service->createStandaloneDuty($planningPeriod, $dutyType, $this->localTime('2027-03-14 08:00'), $this->localTime('2027-03-14 20:00'));

        self::assertTrue($first->overlapsWith($second));
        self::assertFalse($first->overlapsWith($third), 'back-to-back duties (end == start) must not count as overlapping');
    }

    private function localTime(string $value): \DateTimeImmutable
    {
        return new \DateTimeImmutable($value);
    }
}
