<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\UserAvailabilityEvent;
use App\Entity\UserAvailabilityType;
use App\Repository\UserAvailabilityEventRepository;
use App\Service\UserAvailabilityService;
use App\Tests\PlanningDomainTestHelpers;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * D220 — a period's history (D217) reads the journal over local midnights of
 * the planning: an event is listed exactly when its dates meet that range of
 * instants, never a range shifted by the offset.
 */
final class AvailabilityEventRangeTest extends KernelTestCase
{
    use PlanningDomainTestHelpers;

    public function testTheHistoryWindowComparesInstants(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $service = self::getContainer()->get(UserAvailabilityService::class);
        $repository = self::getContainer()->get(UserAvailabilityEventRepository::class);
        $user = $this->createUser($em);
        $brussels = new \DateTimeZone('Europe/Brussels');

        // Local days 2027-06-07 → 2027-07-04 (summer, +02:00).
        $from = new \DateTimeImmutable('2027-06-07 00:00', $brussels);
        $to = new \DateTimeImmutable('2027-07-05 00:00', $brussels);

        $endsInFirstHours = $service->create($user, UserAvailabilityType::UNAVAILABLE, new \DateTimeImmutable('2027-06-06T20:00:00Z'), new \DateTimeImmutable('2027-06-06T23:30:00Z'));
        $service->create($user, UserAvailabilityType::UNAVAILABLE, new \DateTimeImmutable('2027-07-04T22:00:00Z'), new \DateTimeImmutable('2027-07-05T22:00:00Z')); // the whole day after
        $service->create($user, UserAvailabilityType::UNAVAILABLE, new \DateTimeImmutable('2027-06-05T22:00:00Z'), new \DateTimeImmutable('2027-06-06T19:00:00Z')); // before

        $listed = array_map(static fn (UserAvailabilityEvent $e): string => (string) $e->getAvailabilityStableId(), $repository->findTouching([$user], $from, $to));

        self::assertSame([(string) $endsInFirstHours->getStableId()], $listed);
    }
}
