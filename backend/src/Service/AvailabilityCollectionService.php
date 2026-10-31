<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AvailabilityCollection;
use App\Entity\AvailabilityCollectionResponse;
use App\Entity\Planning;
use App\Entity\PlanningCycle;
use App\Entity\User;
use App\Exception\AvailabilityCollectionClosedException;
use App\Exception\AvailabilityCollectionOutsidePeriodException;
use App\Exception\AvailabilityCollectionOutsidePlanningException;
use App\Exception\AvailabilityCollectionOverlapException;
use App\Exception\InvalidAvailabilityDeadlineException;
use App\Exception\NoOpenAvailabilityCollectionException;
use App\Repository\AvailabilityCollectionRepository;
use App\Repository\AvailabilityCollectionResponseRepository;
use App\Repository\PeriodPreparationRepository;
use App\Repository\PlanningCycleRepository;
use App\Repository\PlanningTeamMemberRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * Owns the availability collections: the windows of a planning whose
 * participants are invited to keep their calendar up to date
 * (docs/availability-collection.md, D120-D124, D214).
 *
 * docs/decisions.md D214: nobody confirms anything any more. A person
 * without any unavailability is available; a collection only says who is
 * concerned (its participants), until when an update is wished (an
 * informative deadline) and when it was opened. The confirmations given
 * before D214 stay recorded as history, never asked for nor counted.
 *
 * Strictly separate from UserAvailabilityService: nothing here reads or
 * writes eligibility. The only bridge is one-way and informational —
 * UserAvailabilityService tells this class that a calendar period changed so
 * lastAvailabilityChangeAt can be updated.
 */
final class AvailabilityCollectionService
{
    public function __construct(
        private readonly AvailabilityCollectionRepository $collectionRepository,
        private readonly AvailabilityCollectionResponseRepository $responseRepository,
        private readonly PlanningTeamMemberRepository $teamMemberRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
        private readonly PlanningCycleRepository $cycleRepository,
        private readonly PeriodPreparationRepository $preparationRepository,
    ) {
    }

    /**
     * Opens a collection over $window and one response (a participant row)
     * per person concerned (memberships intersecting the window). Never
     * called for an empty window: DateWindow itself rejects that.
     *
     * A collection belongs to exactly one period (docs/planning-cycles.md I12,
     * §5.9): its window must lie inside one cycle — $cycle when given, else
     * whichever contains it. Its answers are its own: nothing is copied from
     * another collection.
     *
     * @throws AvailabilityCollectionOutsidePlanningException
     * @throws AvailabilityCollectionOutsidePeriodException
     * @throws AvailabilityCollectionOverlapException
     * @throws InvalidAvailabilityDeadlineException
     */
    public function open(Planning $planning, DateWindow $window, User $createdBy, ?\DateTimeImmutable $deadline = null, ?PlanningCycle $cycle = null): AvailabilityCollection
    {
        if ($window->startsAt < $planning->getStartsAt() || $window->endsAt > $planning->getEndsAt()) {
            throw new AvailabilityCollectionOutsidePlanningException();
        }
        $cycle ??= $this->cycleContaining($planning, $window);
        if (null === $cycle || $cycle->getPlanning() !== $planning || !$cycle->contains($window->startsAt, $window->endsAt)) {
            throw new AvailabilityCollectionOutsidePeriodException();
        }
        if ([] !== $this->collectionRepository->findOverlapping($planning, $window->startsAt, $window->endsAt)) {
            throw new AvailabilityCollectionOverlapException();
        }
        $this->assertDeadlineNotPast($planning, $deadline);

        $now = $this->now();
        $collection = new AvailabilityCollection($planning, $window->startsAt, $window->endsAt, $createdBy, $now, $deadline);
        $collection->assignToCycle($cycle);
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
     * docs/decisions.md D207: the untouched collection of a blank period takes
     * the period's new dates, and its expected people are those of the new
     * window, as open() would have chosen them — a PENDING response nobody
     * touched is removed when its person no longer takes part, one is added
     * for a person who now does. Only ever called by
     * PlanningCycleService::changeDates(), under the planning's lock, for the
     * collection PlanningCycleRepository::dateFacts() named untouched.
     */
    public function followPeriod(AvailabilityCollection $collection): void
    {
        $now = $this->now();
        $collection->followCycle($now);
        $planning = $collection->getPlanning();

        $expected = [];
        foreach ($this->teamMemberRepository->findIntersectingForPlanning($planning, $collection->getStartsAt(), $collection->getEndsAt()) as $member) {
            $user = $member->getUser();
            if ($user->isActive()) {
                $expected[$user->getId()] = $user;
            }
        }
        foreach ($this->responseRepository->findByCollection($collection) as $response) {
            if (isset($expected[$response->getUser()->getId()])) {
                unset($expected[$response->getUser()->getId()]);
            } elseif ($response->isParticipant() && !$response->isAcknowledged()) {
                $this->entityManager->remove($response);
            }
        }
        foreach ($expected as $user) {
            $this->entityManager->persist(new AvailabilityCollectionResponse($collection, $user, $now));
        }
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
    public function changePlanningDeadline(Planning $planning, ?\DateTimeImmutable $deadline, ?PlanningCycle $cycle = null): int
    {
        // docs/planning-cycles.md §5.9: the deadline of the selected period's open collections only.
        $open = array_values(array_filter(
            $this->collectionRepository->findOpenByPlanning($planning),
            static fn (AvailabilityCollection $collection): bool => null === $cycle || $collection->getCycle() === $cycle,
        ));
        if ([] === $open) {
            throw new NoOpenAvailabilityCollectionException();
        }
        $this->assertDeadlineNotPast($planning, $deadline);

        $now = $this->now();
        $cycles = [];
        foreach ($open as $collection) {
            $collection->changeDeadline($deadline, $now);
            if (null !== $collection->getCycle()) {
                $cycles[(int) $collection->getCycle()->getId()] = $collection->getCycle();
            }
        }
        // docs/decisions.md D214: one deadline per period — the preparation of the period says the same.
        foreach ($cycles as $collectionCycle) {
            $this->preparationRepository->findOneByCycle($collectionCycle)?->changeDeclarationDeadline($deadline);
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
     * Called when $user's membership in $planning ends: they stop being a
     * participant of every OPEN collection that starts on or after that date.
     * A window they partly took part in is left untouched.
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
     * How many people the collection concerns (its participants, those who
     * left before it started excluded). docs/decisions.md D214: nobody is
     * asked to confirm anything, so there is nothing else to count.
     *
     * @return array{participants: int}
     */
    public function progress(AvailabilityCollection $collection): array
    {
        return ['participants' => \count($this->participantResponses($collection))];
    }

    /**
     * The participants of a collection — the people an announcement or a
     * reminder addresses (docs/availability-collection.md §9), whether or not
     * they already entered absences: their calendar stays open to them.
     *
     * @return list<AvailabilityCollectionResponse>
     */
    public function participantResponses(AvailabilityCollection $collection): array
    {
        return array_values(array_filter(
            $this->responseRepository->findByCollection($collection),
            static fn (AvailabilityCollectionResponse $response): bool => $response->isParticipant(),
        ));
    }

    private function cycleContaining(Planning $planning, DateWindow $window): ?PlanningCycle
    {
        foreach ($this->cycleRepository->findByPlanning($planning) as $cycle) {
            if ($cycle->contains($window->startsAt, $window->endsAt)) {
                return $cycle;
            }
        }

        return null;
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
