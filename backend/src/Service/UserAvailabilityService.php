<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use App\Entity\UserAvailabilityEvent;
use App\Entity\UserAvailabilityEventAction;
use App\Entity\UserAvailabilityEventOrigin;
use App\Entity\UserAvailabilityPeriod;
use App\Entity\UserAvailabilitySource;
use App\Entity\UserAvailabilityType;
use App\Exception\ImportedAvailabilityPeriodException;
use App\Exception\OverlappingUserAvailabilityPeriodException;
use App\Repository\SurgicalHubImportedLeaveRepository;
use App\Repository\UserAvailabilityPeriodRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

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
 * The calendar is editable at any time, whatever the state of a planning
 * (docs/decisions.md D214): no deadline, generation or publication refuses a
 * change, and a change never touches an assignment — a generation uses what
 * its snapshot captured, a later one what is there then.
 *
 * Every write, in the same transaction:
 * - records one UserAvailabilityEvent (docs/decisions.md D215) — a change
 *   that changes nothing records nothing;
 * - tells AvailabilityCollectionService which dates changed, so an open
 *   collection notes when the person last touched their calendar inside its
 *   window (docs/availability-collection.md §6).
 */
final class UserAvailabilityService
{
    public function __construct(
        private readonly UserAvailabilityPeriodRepository $repository,
        private readonly EntityManagerInterface $entityManager,
        private readonly AvailabilityCollectionService $collectionService,
        private readonly SurgicalHubImportedLeaveRepository $importRepository,
        private readonly AvailabilityImpactService $impactService,
        private readonly ClockInterface $clock,
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
        return $this->createAs($user, $user, $type, $startsAt, $endsAt)->period;
    }

    /**
     * @throws OverlappingUserAvailabilityPeriodException
     */
    public function createAs(
        User $user,
        User $actor,
        UserAvailabilityType $type,
        \DateTimeImmutable $startsAt,
        \DateTimeImmutable $endsAt,
    ): CalendarChange {
        if ([] !== $this->repository->findOverlappingOrTouchingForUser($user, $type, $startsAt, $endsAt)) {
            throw new OverlappingUserAvailabilityPeriodException();
        }

        $period = new UserAvailabilityPeriod($user, $type, $startsAt, $endsAt);

        return $this->entityManager->wrapInTransaction(function () use ($period, $user, $actor, $startsAt, $endsAt): CalendarChange {
            $this->entityManager->persist($period);
            $impacts = $this->impactsOf($user, null, $period);
            $this->record($period, $actor, UserAvailabilityEventAction::CREATED, null, $impacts);
            $this->entityManager->flush();
            $this->collectionService->recordAvailabilityChange($user, [[$startsAt, $endsAt]]);

            return new CalendarChange($period, $impacts);
        });
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
        ?User $actor = null,
    ): CalendarChange {
        if ($period->isImported()) {
            throw new ImportedAvailabilityPeriodException();
        }

        if ($type === $period->getType() && $startsAt == $period->getStartsAt() && $endsAt == $period->getEndsAt()) {
            // Nothing changes: no write, no event (a retried or repeated request).
            return new CalendarChange($period, []);
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

        return $this->change($period, $type, $startsAt, $endsAt, $actor ?? $period->getUser());
    }

    /**
     * An imported period can be removed by its owner only once no current
     * association keeps it in sync anymore (revoked, or never mapped): left
     * behind by a revocation, it would otherwise stay read-only for ever
     * (docs/surgicalhub-integration.md §9).
     *
     * @throws ImportedAvailabilityPeriodException $period is still synchronised from SurgicalHub
     */
    public function delete(UserAvailabilityPeriod $period, ?User $actor = null): void
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

        $this->remove($period, $actor ?? $period->getUser());
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
     * exactly like a manual entry. Journaled with no human actor.
     */
    public function importCreate(User $user, \DateTimeImmutable $startsAt, \DateTimeImmutable $endsAt): UserAvailabilityPeriod
    {
        $period = new UserAvailabilityPeriod($user, UserAvailabilityType::UNAVAILABLE, $startsAt, $endsAt, UserAvailabilitySource::SURGICAL_HUB);

        $this->entityManager->wrapInTransaction(function () use ($period, $user, $startsAt, $endsAt): void {
            $this->entityManager->persist($period);
            $this->record($period, null, UserAvailabilityEventAction::CREATED, null, $this->impactsOf($user, null, $period));
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
        if ($startsAt == $period->getStartsAt() && $endsAt == $period->getEndsAt()) {
            return;
        }

        $this->change($period, UserAvailabilityType::UNAVAILABLE, $startsAt, $endsAt, null);
    }

    public function importDelete(UserAvailabilityPeriod $period): void
    {
        if (!$period->isImported()) {
            throw new \LogicException('The synchronisation never deletes a manual period.');
        }

        $this->remove($period, null);
    }

    private function change(UserAvailabilityPeriod $period, UserAvailabilityType $type, \DateTimeImmutable $startsAt, \DateTimeImmutable $endsAt, ?User $actor): CalendarChange
    {
        $previous = [$period->getType(), $period->getStartsAt(), $period->getEndsAt()];

        return $this->entityManager->wrapInTransaction(function () use ($period, $type, $startsAt, $endsAt, $previous, $actor): CalendarChange {
            $period->reschedule($type, $startsAt, $endsAt);
            $impacts = $this->impactsOf($period->getUser(), $previous, $period);
            $this->record($period, $actor, UserAvailabilityEventAction::RESCHEDULED, $previous, $impacts);
            $this->entityManager->flush();
            // Both the dates left and the dates taken count as "touched".
            $this->collectionService->recordAvailabilityChange($period->getUser(), [[$previous[1], $previous[2]], [$startsAt, $endsAt]]);

            return new CalendarChange($period, $impacts);
        });
    }

    private function remove(UserAvailabilityPeriod $period, ?User $actor): void
    {
        $user = $period->getUser();
        $range = [$period->getStartsAt(), $period->getEndsAt()];
        $previous = [$period->getType(), $period->getStartsAt(), $period->getEndsAt()];

        $this->entityManager->wrapInTransaction(function () use ($period, $user, $range, $previous, $actor): void {
            $this->record($period, $actor, UserAvailabilityEventAction::DELETED, $previous, null);
            $this->entityManager->remove($period);
            $this->entityManager->flush();
            $this->collectionService->recordAvailabilityChange($user, [$range]);
        });
    }

    /**
     * What the new unavailable days of $period meet (docs/decisions.md D216).
     * A preference never warns; a change warns only about the days it adds.
     *
     * @param array{0: UserAvailabilityType, 1: \DateTimeImmutable, 2: \DateTimeImmutable}|null $previous
     *
     * @return list<array<string, mixed>>
     */
    private function impactsOf(User $user, ?array $previous, UserAvailabilityPeriod $period): array
    {
        if (UserAvailabilityType::UNAVAILABLE !== $period->getType()) {
            return [];
        }
        $before = null !== $previous && UserAvailabilityType::UNAVAILABLE === $previous[0] ? [$previous[1], $previous[2]] : null;

        return $this->impactService->impacts($user, AvailabilityImpactService::addedRanges($before, [$period->getStartsAt(), $period->getEndsAt()]));
    }

    /**
     * @param array{0: UserAvailabilityType, 1: \DateTimeImmutable, 2: \DateTimeImmutable}|null $previous
     * @param list<array<string, mixed>>|null                                                   $impacts
     */
    private function record(UserAvailabilityPeriod $period, ?User $actor, UserAvailabilityEventAction $action, ?array $previous, ?array $impacts): void
    {
        $this->entityManager->persist(new UserAvailabilityEvent(
            $period->getUser(),
            $actor,
            null === $actor ? UserAvailabilityEventOrigin::SURGICAL_HUB_SYNC : UserAvailabilityEventOrigin::USER,
            $action,
            $period->getStableId(),
            $period->getSource(),
            $previous,
            UserAvailabilityEventAction::DELETED === $action ? null : [$period->getType(), $period->getStartsAt(), $period->getEndsAt()],
            [] === $impacts ? null : $impacts,
            $this->clock->now(),
        ));
    }
}
