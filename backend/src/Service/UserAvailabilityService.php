<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use App\Entity\UserAvailabilityPeriod;
use App\Entity\UserAvailabilitySource;
use App\Entity\UserAvailabilityType;
use App\Exception\ImportedAvailabilityPeriodException;
use App\Exception\OverlappingUserAvailabilityPeriodException;
use App\Repository\SurgicalHubImportedLeaveRepository;
use App\Repository\UserAvailabilityPeriodRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Owns the overlap invariant for a User's personal calendar
 * (docs/availability.md §Chevauchement): two MANUAL periods of the same
 * UserAvailabilityType may never overlap or touch, but UNAVAILABLE and
 * PREFER_DUTY may coexist on the same dates — the future engine treats the
 * hard signal as dominant, this service does not need to arbitrate it.
 *
 * Periods imported from SurgicalHub (docs/surgicalhub-integration.md) go
 * through the import*() methods only: they may overlap anything, the owner's
 * own create/reschedule/delete refuse to touch them, and the import*()
 * methods refuse to touch a manual one.
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
        private readonly SurgicalHubImportedLeaveRepository $importRepository,
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
     * @throws ImportedAvailabilityPeriodException        $period comes from SurgicalHub
     */
    public function reschedule(
        UserAvailabilityPeriod $period,
        UserAvailabilityType $type,
        \DateTimeImmutable $startsAt,
        \DateTimeImmutable $endsAt,
    ): void {
        if ($period->isImported()) {
            throw new ImportedAvailabilityPeriodException();
        }

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

    /**
     * An imported period can be removed by its owner only once no current
     * association keeps it in sync anymore (revoked, or never mapped): left
     * behind by a revocation, it would otherwise stay read-only for ever
     * (docs/surgicalhub-integration.md §9).
     *
     * @throws ImportedAvailabilityPeriodException $period is still synchronised from SurgicalHub
     */
    public function delete(UserAvailabilityPeriod $period): void
    {
        if ($period->isImported()) {
            if ($this->isSynchronised($period)) {
                throw new ImportedAvailabilityPeriodException();
            }

            $import = $this->importRepository->findOneByPeriod($period);
            if (null !== $import) {
                $this->entityManager->remove($import);
            }
        }

        $this->remove($period);
    }

    /** Whether an imported period is still kept up to date by a current (ACTIVE or SUSPENDED) association. */
    public function isSynchronised(UserAvailabilityPeriod $period): bool
    {
        return $period->isImported()
            && isset($this->importRepository->findSynchronisedPeriodIds($period->getUser())[(int) $period->getId()]);
    }

    /**
     * A SurgicalHub absence entering the calendar (docs/surgicalhub-integration.md
     * §7.3). Always UNAVAILABLE, never checked against other periods (an
     * imported period may overlap anything), and noted by open collections
     * exactly like a manual entry — never as an answer to them.
     */
    public function importCreate(User $user, \DateTimeImmutable $startsAt, \DateTimeImmutable $endsAt): UserAvailabilityPeriod
    {
        $period = new UserAvailabilityPeriod($user, UserAvailabilityType::UNAVAILABLE, $startsAt, $endsAt, UserAvailabilitySource::SURGICAL_HUB);

        $this->entityManager->wrapInTransaction(function () use ($period, $user, $startsAt, $endsAt): void {
            $this->entityManager->persist($period);
            $this->entityManager->flush();
            $this->collectionService->recordAvailabilityChange($user, [[$startsAt, $endsAt]]);
        });

        return $period;
    }

    public function importReschedule(UserAvailabilityPeriod $period, \DateTimeImmutable $startsAt, \DateTimeImmutable $endsAt): void
    {
        if (!$period->isImported()) {
            throw new \LogicException('The synchronisation never changes a manual period.');
        }

        $previous = [$period->getStartsAt(), $period->getEndsAt()];

        $this->entityManager->wrapInTransaction(function () use ($period, $startsAt, $endsAt, $previous): void {
            $period->reschedule(UserAvailabilityType::UNAVAILABLE, $startsAt, $endsAt);
            $this->entityManager->flush();
            $this->collectionService->recordAvailabilityChange($period->getUser(), [$previous, [$startsAt, $endsAt]]);
        });
    }

    public function importDelete(UserAvailabilityPeriod $period): void
    {
        if (!$period->isImported()) {
            throw new \LogicException('The synchronisation never deletes a manual period.');
        }

        $this->remove($period);
    }

    private function remove(UserAvailabilityPeriod $period): void
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
