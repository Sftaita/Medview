<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\TeamMemberNonParticipationPeriod;
use App\Entity\TeamMemberRole;
use App\Entity\UserAvailabilityPeriod;
use App\Entity\UserAvailabilityType;
use App\Repository\TeamMemberNonParticipationPeriodRepository;
use App\Repository\UserAvailabilityPeriodRepository;
use App\Service\PlanningTeamMembershipService;
use App\Tests\PlanningDomainTestHelpers;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * D220 — the range reads (snapshot, collection status and counts, absence
 * export, reassignment candidates) compare instants: bounds built as local
 * midnights of Europe/Brussels select exactly what intersects them, never a
 * range shifted by their offset.
 */
final class IntersectingInstantsTest extends KernelTestCase
{
    use PlanningDomainTestHelpers;

    public function testAvailabilityRangeReadsCompareInstants(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $repository = self::getContainer()->get(UserAvailabilityPeriodRepository::class);
        $user = $this->createUser($em);
        $brussels = new \DateTimeZone('Europe/Brussels');

        // The window: local days 2027-10-03 → 2027-10-30 (fall-back day 2027-10-31 just after).
        $from = new \DateTimeImmutable('2027-10-03 00:00', $brussels);
        $to = new \DateTimeImmutable('2027-10-31 00:00', $brussels);

        $endsInFirstHour = $this->absence($em, $user, '2027-10-02T21:00:00Z', '2027-10-02T22:30:00Z');
        $lastHour = $this->absence($em, $user, '2027-10-30T21:00:00Z', '2027-10-30T21:45:00Z'); // the last local hour
        $this->absence($em, $user, '2027-10-30T22:00:00Z', '2027-10-31T23:00:00Z'); // the 25-hour day after
        $this->absence($em, $user, '2027-09-30T22:00:00Z', '2027-10-01T22:00:00Z'); // a whole day before

        $ids = static fn (array $periods): array => array_map(static fn (UserAvailabilityPeriod $p): int => (int) $p->getId(), $periods);
        $expected = [$endsInFirstHour->getId(), $lastHour->getId()];

        self::assertSame($expected, $ids($repository->findIntersecting($user, $from, $to)));
        self::assertSame($expected, $ids($repository->findByTypeIntersectingForUsers([$user], UserAvailabilityType::UNAVAILABLE, $from, $to)));
        // The same instants written in UTC select the same periods.
        self::assertSame($expected, $ids($repository->findIntersecting($user, $from->setTimezone(new \DateTimeZone('UTC')), $to->setTimezone(new \DateTimeZone('UTC')))));
    }

    public function testNonParticipationRangeReadCompareInstants(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $repository = self::getContainer()->get(TeamMemberNonParticipationPeriodRepository::class);
        $member = self::getContainer()->get(PlanningTeamMembershipService::class)
            ->addMember($this->createTeam($em), $this->createUser($em), TeamMemberRole::MEMBER, $this->date('2026-01-01'));
        $brussels = new \DateTimeZone('Europe/Brussels');

        // Local days 2027-03-28 (23 hours, spring forward) → 2027-04-24.
        $from = new \DateTimeImmutable('2027-03-28 00:00', $brussels);
        $to = new \DateTimeImmutable('2027-04-25 00:00', $brussels);

        $kept = new TeamMemberNonParticipationPeriod($member, new \DateTimeImmutable('2027-03-27T20:00:00Z'), new \DateTimeImmutable('2027-03-27T23:30:00Z'));
        $em->persist($kept);
        $em->persist(new TeamMemberNonParticipationPeriod($member, new \DateTimeImmutable('2027-04-24T22:00:00Z'), new \DateTimeImmutable('2027-04-25T22:00:00Z')));
        $em->flush();

        $found = $repository->findIntersecting($member, $from, $to);
        self::assertSame([$kept->getId()], array_map(static fn (TeamMemberNonParticipationPeriod $p): ?int => $p->getId(), $found));
    }

    private function absence(EntityManagerInterface $em, \App\Entity\User $user, string $startsAt, string $endsAt): UserAvailabilityPeriod
    {
        $period = new UserAvailabilityPeriod($user, UserAvailabilityType::UNAVAILABLE, new \DateTimeImmutable($startsAt), new \DateTimeImmutable($endsAt));
        $em->persist($period);
        $em->flush();

        return $period;
    }
}
