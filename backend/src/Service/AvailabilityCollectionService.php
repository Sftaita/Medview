<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AvailabilityAcknowledgementKind;
use App\Entity\AvailabilityCollection;
use App\Entity\AvailabilityCollectionResponse;
use App\Entity\AvailabilityResponseStatus;
use App\Entity\Planning;
use App\Entity\User;
use App\Entity\UserAvailabilityType;
use App\Exception\AvailabilityCollectionClosedException;
use App\Exception\AvailabilityCollectionOutsidePlanningException;
use App\Exception\AvailabilityCollectionOverlapException;
use App\Exception\ConflictingUnavailabilityException;
use App\Exception\InvalidAvailabilityDeadlineException;
use App\Exception\NoOpenAvailabilityCollectionException;
use App\Exception\NotAnAvailabilityRespondentException;
use App\Repository\AvailabilityCollectionRepository;
use App\Repository\AvailabilityCollectionResponseRepository;
use App\Repository\PlanningTeamMemberRepository;
use App\Repository\UserAvailabilityPeriodRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * Owns the "have you reviewed your availabilities for this slice of the
 * planning?" workflow (docs/availability-collection.md, D120-D124).
 *
 * Strictly separate from UserAvailabilityService: this class records that a
 * person *answered*, never what their availability is, and nothing here
 * ever reads or writes eligibility. The only bridge is one-way and
 * informational — UserAvailabilityService tells this class that a calendar
 * period changed so lastAvailabilityChangeAt can be updated.
 */
final class AvailabilityCollectionService
{
    public function __construct(
        private readonly AvailabilityCollectionRepository $collectionRepository,
        private readonly AvailabilityCollectionResponseRepository $responseRepository,
        private readonly PlanningTeamMemberRepository $teamMemberRepository,
        private readonly UserAvailabilityPeriodRepository $availabilityPeriodRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * Opens a collection over $window and one PENDING response per person
     * expected to answer (memberships intersecting the window). Never
     * called for an empty window: DateWindow itself rejects that.
     *
     * @throws AvailabilityCollectionOutsidePlanningException
     * @throws AvailabilityCollectionOverlapException
     * @throws InvalidAvailabilityDeadlineException
     */
    public function open(Planning $planning, DateWindow $window, User $createdBy, ?\DateTimeImmutable $deadline = null): AvailabilityCollection
    {
        if ($window->startsAt < $planning->getStartsAt() || $window->endsAt > $planning->getEndsAt()) {
            throw new AvailabilityCollectionOutsidePlanningException();
        }
        if ([] !== $this->collectionRepository->findOverlapping($planning, $window->startsAt, $window->endsAt)) {
            throw new AvailabilityCollectionOverlapException();
        }
        $this->assertDeadlineNotPast($planning, $deadline);

        $now = $this->now();
        $collection = new AvailabilityCollection($planning, $window->startsAt, $window->endsAt, $createdBy, $now, $deadline);
        $this->entityManager->persist($collection);

        $expected = [];
        foreach ($this->teamMemberRepository->findIntersectingForPlanning($planning, $window->startsAt, $window->endsAt) as $member) {
            $user = $member->getUser();
            if ($user->isActive()) {
                $expected[$user->getId()] = $user;
            }
        }
        foreach ($expected as $user) {
            $this->entityManager->persist(new AvailabilityCollectionResponse($collection, $user, $now));
        }

        $this->entityManager->flush();

        return $collection;
    }

    /**
     * @throws AvailabilityCollectionClosedException
     * @throws InvalidAvailabilityDeadlineException
     */
    public function changeDeadline(AvailabilityCollection $collection, ?\DateTimeImmutable $deadline): void
    {
        if (!$collection->isOpen()) {
            throw new AvailabilityCollectionClosedException();
        }
        $this->assertDeadlineNotPast($collection->getPlanning(), $deadline);

        $collection->changeDeadline($deadline, $this->now());
        $this->entityManager->flush();
    }

    /**
     * The planning-level "date souhaitée de fin d'encodage" (docs/decisions.md
     * D127): the deadline of every OPEN collection of the planning, set in one
     * transaction. Informative only — nothing anywhere refuses an answer, an
     * edit of the calendar or a generation because it is passed. Closed
     * collections are frozen history and keep theirs. Returns how many
     * collections were updated.
     *
     * @throws NoOpenAvailabilityCollectionException
     * @throws InvalidAvailabilityDeadlineException
     */
    public function changePlanningDeadline(Planning $planning, ?\DateTimeImmutable $deadline): int
    {
        $open = $this->collectionRepository->findOpenByPlanning($planning);
        if ([] === $open) {
            throw new NoOpenAvailabilityCollectionException();
        }
        $this->assertDeadlineNotPast($planning, $deadline);

        $now = $this->now();
        foreach ($open as $collection) {
            $collection->changeDeadline($deadline, $now);
        }
        $this->entityManager->flush();

        return \count($open);
    }

    public function close(AvailabilityCollection $collection): void
    {
        $collection->close($this->now());
        $this->entityManager->flush();
    }

    /**
     * The explicit answer. Idempotent (double click, two tabs): the first
     * confirmation wins and any later call returns it untouched. The
     * response row is locked for the duration of the (short) transaction so
     * two concurrent calls cannot both write.
     *
     * @throws NotAnAvailabilityRespondentException  when $user is not an expected respondent
     * @throws AvailabilityCollectionClosedException
     * @throws ConflictingUnavailabilityException    when NO_UNAVAILABILITY is claimed but UNAVAILABLE periods exist in the window
     */
    public function acknowledge(AvailabilityCollection $collection, User $user, AvailabilityAcknowledgementKind $kind): AvailabilityCollectionResponse
    {
        // Domain refusals are *returned* out of the transaction and thrown
        // afterwards: throwing inside wrapInTransaction() would close the
        // EntityManager, and nothing has been written on those paths anyway.
        $outcome = $this->entityManager->wrapInTransaction(function () use ($collection, $user, $kind): AvailabilityCollectionResponse|\RuntimeException {
            $found = $this->responseRepository->findOneForUser($collection, $user);
            if (null === $found) {
                return new NotAnAvailabilityRespondentException();
            }

            // Re-read under a write lock: a concurrent acknowledge that committed first is seen here.
            $this->entityManager->refresh($found, LockMode::PESSIMISTIC_WRITE);

            if ($found->isAcknowledged()) {
                return $found;
            }
            if (AvailabilityResponseStatus::WITHDRAWN === $found->getStatus()) {
                return new NotAnAvailabilityRespondentException();
            }
            if (!$collection->isOpen()) {
                return new AvailabilityCollectionClosedException();
            }

            if (AvailabilityAcknowledgementKind::NO_UNAVAILABILITY === $kind) {
                $unavailable = $this->countUnavailablePeriods($collection, $user);
                if ($unavailable > 0) {
                    return new ConflictingUnavailabilityException($unavailable);
                }
            }

            $found->acknowledge($kind, $this->now());
            $this->entityManager->flush();

            return $found;
        });

        if ($outcome instanceof \RuntimeException) {
            throw $outcome;
        }

        return $outcome;
    }

    /**
     * Called when $user joins $planning: they become an expected respondent
     * of every OPEN collection whose window they will take part in, and are
     * reinstated if they had been withdrawn from it earlier. Closed
     * collections are frozen history and never gain a respondent.
     */
    public function registerMember(Planning $planning, User $user, \DateTimeImmutable $membershipStart): void
    {
        if (!$user->isActive()) {
            return;
        }

        $now = $this->now();
        foreach ($this->collectionRepository->findOpenByPlanning($planning) as $collection) {
            // An open-ended membership taking part in the window from `membershipStart`.
            if ($membershipStart >= $collection->getEndsAt()) {
                continue;
            }

            $response = $this->responseRepository->findOneForUser($collection, $user);
            if (null === $response) {
                $this->entityManager->persist(new AvailabilityCollectionResponse($collection, $user, $now));
            } else {
                $response->reinstate($now);
            }
        }

        $this->entityManager->flush();
    }

    /**
     * Called when $user's membership in $planning ends: they stop being
     * expected in every OPEN collection that starts on or after that date
     * and that they have not answered. A window they partly took part in,
     * and any answer already given, are left untouched.
     */
    public function withdrawMember(Planning $planning, User $user, \DateTimeImmutable $membershipEnd): void
    {
        $now = $this->now();
        foreach ($this->collectionRepository->findOpenByPlanning($planning) as $collection) {
            if ($collection->getStartsAt() < $membershipEnd) {
                continue;
            }

            $this->responseRepository->findOneForUser($collection, $user)?->withdraw($now);
        }

        $this->entityManager->flush();
    }

    /**
     * Records that $user touched their calendar over [$from, $to) — called
     * (in the same transaction) by UserAvailabilityService. Only touches
     * OPEN collections whose window the range intersects; never changes
     * whether the person has answered (docs/decisions.md D121).
     *
     * @param list<array{0: \DateTimeImmutable, 1: \DateTimeImmutable}> $ranges
     */
    public function recordAvailabilityChange(User $user, array $ranges): void
    {
        $now = $this->now();
        $touched = false;

        foreach ($this->responseRepository->findActiveInOpenCollectionsForUser($user) as $response) {
            $collection = $response->getCollection();
            foreach ($ranges as [$from, $to]) {
                if ($from < $collection->getEndsAtInstant() && $to > $collection->getStartsAtInstant()) {
                    $response->recordAvailabilityChange($now);
                    $touched = true;
                    break;
                }
            }
        }

        if ($touched) {
            $this->entityManager->flush();
        }
    }

    /**
     * @return array{expected: int, acknowledged: int, pending: int}
     */
    public function progress(AvailabilityCollection $collection): array
    {
        $acknowledged = 0;
        $pending = 0;
        foreach ($this->responseRepository->findByCollection($collection) as $response) {
            match ($response->getStatus()) {
                AvailabilityResponseStatus::ACKNOWLEDGED => ++$acknowledged,
                AvailabilityResponseStatus::PENDING => ++$pending,
                AvailabilityResponseStatus::WITHDRAWN => null,
            };
        }

        return ['expected' => $acknowledged + $pending, 'acknowledged' => $acknowledged, 'pending' => $pending];
    }

    /**
     * Exactly the people still expected to answer — the list a future
     * "send a reminder" action would address (docs/availability-collection.md §9).
     *
     * @return list<AvailabilityCollectionResponse>
     */
    public function pendingResponses(AvailabilityCollection $collection): array
    {
        return array_values(array_filter(
            $this->responseRepository->findByCollection($collection),
            static fn (AvailabilityCollectionResponse $response): bool => AvailabilityResponseStatus::PENDING === $response->getStatus(),
        ));
    }

    private function countUnavailablePeriods(AvailabilityCollection $collection, User $user): int
    {
        $periods = $this->availabilityPeriodRepository->findIntersecting($user, $collection->getStartsAtInstant(), $collection->getEndsAtInstant());

        return \count(array_filter(
            $periods,
            static fn ($period): bool => UserAvailabilityType::UNAVAILABLE === $period->getType(),
        ));
    }

    public function assertDeadlineNotPast(Planning $planning, ?\DateTimeImmutable $deadline): void
    {
        if (null === $deadline) {
            return;
        }

        $today = $this->clock->now()->setTimezone(new \DateTimeZone($planning->getTimezone()))->format('Y-m-d');
        if ($deadline->format('Y-m-d') < $today) {
            throw new InvalidAvailabilityDeadlineException();
        }
    }

    /**
     * Always UTC: the `datetime_immutable` columns store the wall-clock of
     * the value they are given, so every writer must use the same zone.
     */
    private function now(): \DateTimeImmutable
    {
        return $this->clock->now()->setTimezone(new \DateTimeZone('UTC'));
    }
}
