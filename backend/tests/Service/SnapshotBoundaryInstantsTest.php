<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Eligibility\SingleDutyUnit;
use App\Entity\PlanningGeneration;
use App\Entity\TeamMemberNonParticipationPeriod;
use App\Entity\UserAvailabilityPeriod;
use App\Entity\UserAvailabilityType;
use App\Service\DutyMaterializationService;
use App\Service\EligibilityMatrixBuilder;
use App\Service\PlanningRuleSetService;
use App\Service\PlanningSnapshotService;
use App\Service\PlanningTeamMembershipService;
use App\Tests\PlanningDomainTestHelpers;
use App\Tests\PlanningGenerationTestHelpers;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * D220 — what a generation's snapshot captures at the edges of its period.
 * The period [startsAt, endsAt[ is resolved into Europe/Brussels instants
 * (local midnight of its first day, local midnight of the day after its last
 * day); duties are whole local days. An absence or a non-participation is
 * captured exactly when it intersects that range of instants, and the
 * eligibility of the first and last days' duties follows — in winter (+01:00),
 * in summer (+02:00) and on the two DST days, whatever the offset of the
 * stored instants.
 */
final class SnapshotBoundaryInstantsTest extends KernelTestCase
{
    use PlanningDomainTestHelpers;
    use PlanningGenerationTestHelpers;

    /**
     * Period [start, end[ (dates), the absence (UTC instants), whether the
     * snapshot must hold it, whether the first and the last day's duty stay
     * eligible.
     *
     * @return iterable<string, array{string, string, string, string, bool, bool, bool}>
     */
    public static function cases(): iterable
    {
        // Winter period 2027-01-04 → 2027-01-31 (instants 2027-01-03T23:00Z → 2027-01-31T23:00Z).
        yield 'winter: ends 00:30 local on the first day' => ['2027-01-04', '2027-02-01', '2027-01-03T20:00:00Z', '2027-01-03T23:30:00Z', true, false, true];
        yield 'winter: whole day before the period' => ['2027-01-04', '2027-02-01', '2027-01-02T23:00:00Z', '2027-01-03T23:00:00Z', false, true, true];
        yield 'winter: whole first day' => ['2027-01-04', '2027-02-01', '2027-01-03T23:00:00Z', '2027-01-04T23:00:00Z', true, false, true];
        yield 'winter: starts 23:30 local on the last day' => ['2027-01-04', '2027-02-01', '2027-01-31T22:30:00Z', '2027-02-01T03:00:00Z', true, true, false];
        yield 'winter: whole day after the period' => ['2027-01-04', '2027-02-01', '2027-01-31T23:00:00Z', '2027-02-01T23:00:00Z', false, true, true];
        yield 'winter: starts 00:30 local the day after' => ['2027-01-04', '2027-02-01', '2027-01-31T23:30:00Z', '2027-02-01T10:00:00Z', false, true, true];
        // Summer period 2027-06-07 → 2027-07-04 (instants 2027-06-06T22:00Z → 2027-07-04T22:00Z).
        yield 'summer: ends 01:30 local on the first day' => ['2027-06-07', '2027-07-05', '2027-06-06T20:00:00Z', '2027-06-06T23:30:00Z', true, false, true];
        yield 'summer: whole day after the period' => ['2027-06-07', '2027-07-05', '2027-07-04T22:00:00Z', '2027-07-05T22:00:00Z', false, true, true];
        yield 'summer: starts 01:00 local the day after' => ['2027-06-07', '2027-07-05', '2027-07-04T23:00:00Z', '2027-07-05T10:00:00Z', false, true, true];
        // Starting on the spring-forward day, 2027-03-28 (23 hours): 2027-03-27T23:00Z → 2027-04-24T22:00Z.
        yield 'DST spring start: ends 00:30 local on the 23-hour first day' => ['2027-03-28', '2027-04-25', '2027-03-27T20:00:00Z', '2027-03-27T23:30:00Z', true, false, true];
        // Ending the day before it: 2027-03-01 → 2027-03-27, to = 2027-03-27T23:00Z.
        yield 'DST spring end: the 23-hour day after the period' => ['2027-03-01', '2027-03-28', '2027-03-27T23:00:00Z', '2027-03-28T22:00:00Z', false, true, true];
        // Starting on the fall-back day, 2027-10-31 (25 hours): 2027-10-30T22:00Z → 2027-11-27T23:00Z.
        yield 'DST autumn start: ends 01:00 local on the 25-hour first day' => ['2027-10-31', '2027-11-28', '2027-10-30T20:00:00Z', '2027-10-30T23:00:00Z', true, false, true];
        // Ending the day before it: 2027-10-03 → 2027-10-30, to = 2027-10-30T22:00Z.
        yield 'DST autumn end: the 25-hour day after the period' => ['2027-10-03', '2027-10-31', '2027-10-30T22:00:00Z', '2027-10-31T23:00:00Z', false, true, true];
        yield 'DST autumn end: last hour of the last day' => ['2027-10-03', '2027-10-31', '2027-10-30T21:30:00Z', '2027-10-30T22:00:00Z', true, true, false];
    }

    #[DataProvider('cases')]
    public function testAnAbsence(string $from, string $to, string $startsAt, string $endsAt, bool $captured, bool $firstEligible, bool $lastEligible): void
    {
        $this->check($from, $to, $startsAt, $endsAt, $captured, $firstEligible, $lastEligible, nonParticipation: false);
    }

    #[DataProvider('cases')]
    public function testANonParticipation(string $from, string $to, string $startsAt, string $endsAt, bool $captured, bool $firstEligible, bool $lastEligible): void
    {
        $this->check($from, $to, $startsAt, $endsAt, $captured, $firstEligible, $lastEligible, nonParticipation: true);
    }

    private function check(string $from, string $to, string $startsAt, string $endsAt, bool $captured, bool $firstEligible, bool $lastEligible, bool $nonParticipation): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $materialization = $container->get(DutyMaterializationService::class);

        $team = $this->createTeam($em);
        self::assertSame('Europe/Brussels', $team->getTimezone());
        $period = $this->createPlanningPeriod($em, $team, $from, $to);
        $this->activateRuleSet($container->get(PlanningRuleSetService::class), $team);
        $user = $this->createUser($em);
        $member = $this->addMember($container->get(PlanningTeamMembershipService::class), $team, $user);
        $dutyType = $this->createDutyType($em, $team);
        $lastDay = (new \DateTimeImmutable($to))->modify('-1 day')->format('Y-m-d');
        $firstDuty = $this->createDuty($materialization, $period, $dutyType, $from.' 00:00', (new \DateTimeImmutable($from))->modify('+1 day')->format('Y-m-d').' 00:00');
        $lastDuty = $this->createDuty($materialization, $period, $dutyType, $lastDay.' 00:00', $to.' 00:00');

        // Persisted as instants (the UTC strings above); the service is bypassed on purpose, the edges are what is tested.
        $source = $nonParticipation
            ? new TeamMemberNonParticipationPeriod($member, new \DateTimeImmutable($startsAt), new \DateTimeImmutable($endsAt))
            : new UserAvailabilityPeriod($user, UserAvailabilityType::UNAVAILABLE, new \DateTimeImmutable($startsAt), new \DateTimeImmutable($endsAt));
        $em->persist($source);
        $em->flush();
        // Read back from the database, as a real generation does.
        $em->clear();
        $period = $em->find($period::class, $period->getId());
        $firstDuty = $em->find($firstDuty::class, $firstDuty->getId());
        $lastDuty = $em->find($lastDuty::class, $lastDuty->getId());

        $generation = new PlanningGeneration($period);
        $em->persist($generation);
        $em->flush();
        $snapshot = $container->get(PlanningSnapshotService::class)->createSnapshot($generation);
        $snapshotMember = $snapshot->getMembers()->first();

        $held = $nonParticipation ? $snapshotMember->getNonParticipationPeriods() : $snapshotMember->getAvailabilityPeriods();
        self::assertCount($captured ? 1 : 0, $held, $captured ? 'The snapshot must hold it.' : 'The snapshot must not hold what lies outside the period.');

        $matrix = $container->get(EligibilityMatrixBuilder::class)->build($snapshot);
        self::assertSame($firstEligible, $matrix->get(new SingleDutyUnit($firstDuty), $snapshotMember)->eligible, 'first day');
        self::assertSame($lastEligible, $matrix->get(new SingleDutyUnit($lastDuty), $snapshotMember)->eligible, 'last day');
    }
}
