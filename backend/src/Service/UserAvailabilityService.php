<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use App\Entity\UserAvailabilityPeriod;
use App\Entity\UserAvailabilityType;
use App\Exception\OverlappingUserAvailabilityPeriodException;
use App\Repository\UserAvailabilityPeriodRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Owns the overlap invariant for a User's personal calendar
 * (docs/availability.md §Chevauchement): two periods of the same
 * UserAvailabilityType may never overlap or touch, but UNAVAILABLE and
 * PREFER_DUTY may coexist on the same dates — the future engine treats the
 * hard signal as dominant, this service does not need to arbitrate it.
 */
final class UserAvailabilityService
{
    public function __construct(
        private readonly UserAvailabilityPeriodRepository $repository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @throws OverlappingUserAvailabilityPeriodException
     */
    public function create(
        User $user,
        UserAvailabilityType $type,
        \DateTimeImmutable $startsAt,
        \DateTimeImmutable $endsAt,
    ): UserAvailabilityPeriod {
        if ([] !== $this->repository->findOverlappingOrTouchingForUser($user, $type, $startsAt, $endsAt)) {
            throw new OverlappingUserAvailabilityPeriodException();
        }

        $period = new UserAvailabilityPeriod($user, $type, $startsAt, $endsAt);
        $this->entityManager->persist($period);
        $this->entityManager->flush();

        return $period;
    }

    /**
     * @throws OverlappingUserAvailabilityPeriodException
     */
    public function reschedule(
        UserAvailabilityPeriod $period,
        UserAvailabilityType $type,
        \DateTimeImmutable $startsAt,
        \DateTimeImmutable $endsAt,
    ): void {
        $overlapping = $this->repository->findOverlappingOrTouchingForUser(
            $period->getUser(),
            $type,
            $startsAt,
            $endsAt,
            excluding: $period,
        );

        if ([] !== $overlapping) {
            throw new OverlappingUserAvailabilityPeriodException();
        }

        $period->reschedule($type, $startsAt, $endsAt);
        $this->entityManager->flush();
    }

    public function delete(UserAvailabilityPeriod $period): void
    {
        $this->entityManager->remove($period);
        $this->entityManager->flush();
    }
}
