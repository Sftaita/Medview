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
 *
 * Every write also tells AvailabilityCollectionService which dates changed
 * (in the same transaction), so an open collection can note that the person
 * touched their calendar inside its window (docs/availability-collection.md
 * §6). That is the only link between the two: the calendar remains the
 * business truth, the collection only records workflow.
 */
final class UserAvailabilityService
{
    public function __construct(
        private readonly UserAvailabilityPeriodRepository $repository,
        private readonly EntityManagerInterface $entityManager,
        private readonly AvailabilityCollectionService $collectionService,
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

        $this->entityManager->wrapInTransaction(function () use ($period, $user, $startsAt, $endsAt): void {
            $this->entityManager->persist($period);
            $this->entityManager->flush();
            $this->collectionService->recordAvailabilityChange($user, [[$startsAt, $endsAt]]);
        });

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

        $previous = [$period->getStartsAt(), $period->getEndsAt()];

        $this->entityManager->wrapInTransaction(function () use ($period, $type, $startsAt, $endsAt, $previous): void {
            $period->reschedule($type, $startsAt, $endsAt);
            $this->entityManager->flush();
            // Both the dates left and the dates taken count as "touched".
            $this->collectionService->recordAvailabilityChange($period->getUser(), [$previous, [$startsAt, $endsAt]]);
        });
    }

    public function delete(UserAvailabilityPeriod $period): void
    {
        $user = $period->getUser();
        $range = [$period->getStartsAt(), $period->getEndsAt()];

        $this->entityManager->wrapInTransaction(function () use ($period, $user, $range): void {
            $this->entityManager->remove($period);
            $this->entityManager->flush();
            $this->collectionService->recordAvailabilityChange($user, [$range]);
        });
    }
}
