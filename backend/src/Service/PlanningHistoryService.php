<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\DutyAssignment;
use App\Entity\DutyAssignmentEvent;
use App\Entity\Planning;
use App\Entity\PlanningCycle;
use App\Entity\PlanningGeneration;
use App\Entity\PlanningSnapshot;
use App\Entity\PlanningSnapshotAvailabilityPeriod;
use App\Entity\PlanningTeamMember;
use App\Entity\User;
use App\Entity\UserAvailabilityEvent;
use App\Entity\UserAvailabilityEventAction;
use App\Entity\UserAvailabilityType;
use App\Repository\DutyAssignmentEventRepository;
use App\Repository\PlanningGenerationRepository;
use App\Repository\PlanningLineRepository;
use App\Repository\PlanningTeamMemberRepository;
use App\Repository\UserAvailabilityEventRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * docs/decisions.md D217 — the chronology of one period of a planning, for
 * its managers: who declared what in their calendar and when, each
 * generation (who launched it, when its data were captured, when it ended,
 * what it used), and each change of a duty's holder (a manager's
 * reassignment, a swap). Optionally narrowed to one participant.
 *
 * Every fact comes from a record written when it happened, never rebuilt:
 * - the calendar journal (UserAvailabilityEvent, D215);
 * - the generations and their snapshots (PlanningGeneration, PlanningSnapshot
 *   and the PlanningSnapshotAvailabilityPeriod rows — what a generation really
 *   used, D129);
 * - the assignment events (DutyAssignmentEvent, D143/D178).
 *
 * A calendar change is said to be "after a generation" when it happened
 * after that generation captured its data; it is said to be "used by a
 * generation" only when that generation's snapshot holds that very entry
 * with these very dates — never inferred from a time alone. A declaration is
 * never presented as a reassignment, nor the reverse.
 *
 * Only the people of the planning and the dates of the period are read: a
 * manager never sees a participant's calendar outside the period.
 */
final class PlanningHistoryService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PlanningLineRepository $lineRepository,
        private readonly PlanningGenerationRepository $generationRepository,
        private readonly PlanningTeamMemberRepository $teamMemberRepository,
        private readonly UserAvailabilityEventRepository $eventRepository,
        private readonly DutyAssignmentEventRepository $assignmentEventRepository,
    ) {
    }

    /**
     * @return list<array<string, mixed>> oldest first
     */
    public function history(Planning $planning, PlanningCycle $cycle, ?PlanningTeamMember $only = null): array
    {
        $zone = new \DateTimeZone($planning->getTimezone());
        $from = new \DateTimeImmutable($cycle->getStartsAt()->format('Y-m-d').' 00:00:00', $zone);
        $to = new \DateTimeImmutable($cycle->getEndsAt()->format('Y-m-d').' 00:00:00', $zone);

        $users = [];
        if (null !== $only) {
            $users[(int) $only->getUser()->getId()] = $only->getUser();
        } else {
            foreach ($this->teamMemberRepository->findIntersectingForPlanning($planning, $cycle->getStartsAt(), $cycle->getEndsAt()) as $member) {
                $users[(int) $member->getUser()->getId()] = $member->getUser();
            }
        }

        $generations = $this->generations($planning, $cycle);
        $captured = $this->capturedAvailabilities($generations);
        $entries = [];

        foreach ($generations as $generation) {
            $entries[] = $this->generationEntry($generation, $captured[(int) $generation->getId()] ?? [], $users, null !== $only);
        }
        foreach ($this->eventRepository->findTouching(array_values($users), $from, $to) as $event) {
            $entries[] = $this->availabilityEntry($event, $generations, $captured);
        }
        foreach ($this->assignmentEventRepository->findByPlanning($planning) as $event) {
            $entry = $this->assignmentEntry($event, $cycle, $only);
            if (null !== $entry) {
                $entries[] = $entry;
            }
        }

        usort($entries, static fn (array $a, array $b): int => [$a['at'], $a['order']] <=> [$b['at'], $b['order']]);

        return array_map(static function (array $entry): array {
            unset($entry['order']);

            return $entry;
        }, $entries);
    }

    /**
     * The generations of the period's lines, oldest first.
     *
     * @return list<PlanningGeneration>
     */
    private function generations(Planning $planning, PlanningCycle $cycle): array
    {
        $generations = [];
        foreach ($this->lineRepository->findByPlanning($planning) as $line) {
            $period = $line->periodIn($cycle);
            if (null === $period) {
                continue;
            }
            foreach ($this->generationRepository->findByPlanningPeriod($period) as $generation) {
                $generations[] = $generation;
            }
        }
        usort($generations, static fn (PlanningGeneration $a, PlanningGeneration $b): int => [$a->getCreatedAt(), $a->getId()] <=> [$b->getCreatedAt(), $b->getId()]);

        return $generations;
    }

    /**
     * What each generation's snapshot holds of the calendars, and when it was taken.
     *
     * @param list<PlanningGeneration> $generations
     *
     * @return array<int, array{capturedAt: ?\DateTimeImmutable, periods: list<PlanningSnapshotAvailabilityPeriod>}> by generation id
     */
    private function capturedAvailabilities(array $generations): array
    {
        $captured = [];
        foreach ($generations as $generation) {
            $captured[(int) $generation->getId()] = ['capturedAt' => null, 'periods' => []];
        }
        if ([] === $generations) {
            return [];
        }

        /** @var list<PlanningSnapshot> $snapshots */
        $snapshots = $this->entityManager->createQueryBuilder()
            ->select('s')
            ->from(PlanningSnapshot::class, 's')
            ->andWhere('s.generation IN (:generations)')
            ->setParameter('generations', $generations)
            ->getQuery()
            ->getResult();
        foreach ($snapshots as $snapshot) {
            $captured[(int) $snapshot->getGeneration()->getId()]['capturedAt'] = $snapshot->getCreatedAt();
        }

        /** @var list<PlanningSnapshotAvailabilityPeriod> $periods */
        $periods = $this->entityManager->createQueryBuilder()
            ->select('a', 'm', 's')
            ->from(PlanningSnapshotAvailabilityPeriod::class, 'a')
            ->join('a.snapshotMember', 'm')
            ->join('m.snapshot', 's')
            ->andWhere('s.generation IN (:generations)')
            ->setParameter('generations', $generations)
            ->getQuery()
            ->getResult();
        foreach ($periods as $period) {
            $captured[(int) $period->getSnapshotMember()->getSnapshot()->getGeneration()->getId()]['periods'][] = $period;
        }

        return $captured;
    }

    /**
     * @param array{capturedAt: ?\DateTimeImmutable, periods: list<PlanningSnapshotAvailabilityPeriod>} $captured
     * @param array<int, User>                                                                          $users
     *
     * @return array<string, mixed>
     */
    private function generationEntry(PlanningGeneration $generation, array $captured, array $users, bool $listUnavailabilities): array
    {
        $byStableId = [];
        foreach ($users as $user) {
            $byStableId[(string) $user->getStableId()] = $user;
        }
        $unavailabilities = array_values(array_filter(
            $captured['periods'] ?? [],
            static fn (PlanningSnapshotAvailabilityPeriod $p): bool => UserAvailabilityType::UNAVAILABLE === $p->getType()
                && isset($byStableId[(string) $p->getSnapshotMember()->getSourceUserStableId()]),
        ));
        $line = $generation->getPlanningPeriod()->getLine();
        $capturedAt = $captured['capturedAt'] ?? null;

        $entry = [
            'kind' => 'generation',
            'at' => self::utc($capturedAt ?? $generation->getCreatedAt()),
            'order' => 1,
            'generationStableId' => (string) $generation->getStableId(),
            'line' => null !== $line ? ['stableId' => (string) $line->getStableId(), 'name' => $line->getName()] : null,
            'launchedBy' => self::person($generation->getCreatedBy()),
            'requestedAt' => self::utc($generation->getCreatedAt()),
            // The moment the calendars were read (the snapshot); null when it never got that far.
            'capturedAt' => null !== $capturedAt ? self::utc($capturedAt) : null,
            'finishedAt' => null !== $generation->getGeneratedAt() ? self::utc($generation->getGeneratedAt()) : null,
            'status' => $generation->getStatus()->value,
            'coverage' => $generation->getCoverageStatus()?->value,
            'failureReason' => $generation->getFailureReason(),
            'capturedUnavailabilityCount' => \count($unavailabilities),
        ];
        if ($listUnavailabilities) {
            $entry['capturedUnavailabilities'] = array_map(static fn (PlanningSnapshotAvailabilityPeriod $p): array => [
                'stableId' => (string) $p->getSourceAvailabilityStableId(),
                'startsAt' => $p->getStartsAt()->format(\DATE_ATOM),
                'endsAt' => $p->getEndsAt()->format(\DATE_ATOM),
                'source' => $p->getProvenance()->value,
            ], $unavailabilities);
        }

        return $entry;
    }

    /**
     * @param list<PlanningGeneration>                                                                              $generations
     * @param array<int, array{capturedAt: ?\DateTimeImmutable, periods: list<PlanningSnapshotAvailabilityPeriod>}> $captured
     *
     * @return array<string, mixed>
     */
    private function availabilityEntry(UserAvailabilityEvent $event, array $generations, array $captured): array
    {
        $after = null;
        $usedBy = [];
        foreach ($generations as $generation) {
            $snapshot = $captured[(int) $generation->getId()] ?? null;
            $capturedAt = $snapshot['capturedAt'] ?? null;
            if (null === $capturedAt) {
                continue;
            }
            $held = UserAvailabilityEventAction::DELETED !== $event->getAction() && self::holds($snapshot['periods'], $event);
            // Both instants are kept to the second: within the same second, what the snapshot holds decides.
            if ($capturedAt < $event->getOccurredAt() || ($capturedAt == $event->getOccurredAt() && !$held)) {
                // The latest generation that had already read the calendars when this change happened.
                $after = $generation;
                continue;
            }
            if ($held) {
                $usedBy[] = self::generationReference($generation, $capturedAt);
            }
        }

        return [
            'kind' => 'availability',
            'at' => self::utc($event->getOccurredAt()),
            'order' => 0,
            'eventStableId' => (string) $event->getStableId(),
            'action' => $event->getAction()->value,
            'origin' => $event->getOrigin()->value,
            'source' => $event->getSource()->value,
            'user' => self::person($event->getUser()),
            'actor' => self::person($event->getActor()),
            'availabilityStableId' => (string) $event->getAvailabilityStableId(),
            'previous' => null === $event->getPreviousStartsAt() ? null : [
                'type' => $event->getPreviousType()?->value,
                'startsAt' => $event->getPreviousStartsAt()->format(\DATE_ATOM),
                'endsAt' => $event->getPreviousEndsAt()?->format(\DATE_ATOM),
            ],
            'new' => null === $event->getNewStartsAt() ? null : [
                'type' => $event->getNewType()?->value,
                'startsAt' => $event->getNewStartsAt()->format(\DATE_ATOM),
                'endsAt' => $event->getNewEndsAt()?->format(\DATE_ATOM),
            ],
            // Made after this generation read the calendars: it did not change it (D214).
            'afterGeneration' => null !== $after ? self::generationReference($after, $captured[(int) $after->getId()]['capturedAt']) : null,
            // Later generations whose snapshot holds this very entry with these very dates.
            'usedBy' => $usedBy,
            // What it met when it was made (D216): generated periods, duties held.
            'impacts' => $event->getImpacts() ?? [],
        ];
    }

    /**
     * @return array<string, mixed>|null null when the duty is not in the period or not about $only
     */
    private function assignmentEntry(DutyAssignmentEvent $event, PlanningCycle $cycle, ?PlanningTeamMember $only): ?array
    {
        $duty = $event->getDuty();
        if ($duty->getPlanningPeriod()->getCycle() !== $cycle) {
            return null;
        }
        $previous = $event->getPreviousAssignment();
        $new = $event->getNewAssignment();
        if (null !== $only) {
            $user = $only->getUser();
            if ($previous?->getTeamMember()->getUser() !== $user && $new?->getTeamMember()->getUser() !== $user) {
                return null;
            }
        }

        return [
            'kind' => 'assignment',
            'at' => self::utc($event->getOccurredAt()),
            'order' => 2,
            'eventStableId' => (string) $event->getStableId(),
            'author' => self::person($event->getAuthor()),
            'swap' => null !== $event->getSwapProposal(),
            'wasPublished' => $event->wasPublished(),
            'duty' => [
                'stableId' => (string) $duty->getStableId(),
                'date' => $duty->getLocalDate()->format('Y-m-d'),
                'lineName' => $duty->getPlanningPeriod()->getLine()?->getName(),
            ],
            'from' => self::holder($previous),
            'to' => self::holder($new),
        ];
    }

    /**
     * @param list<PlanningSnapshotAvailabilityPeriod> $periods
     */
    private static function holds(array $periods, UserAvailabilityEvent $event): bool
    {
        foreach ($periods as $period) {
            if ((string) $period->getSourceAvailabilityStableId() === (string) $event->getAvailabilityStableId()
                && $period->getStartsAt() == $event->getNewStartsAt()
                && $period->getEndsAt() == $event->getNewEndsAt()
                && $period->getType() === $event->getNewType()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private static function generationReference(PlanningGeneration $generation, ?\DateTimeImmutable $capturedAt): array
    {
        return [
            'generationStableId' => (string) $generation->getStableId(),
            'lineName' => $generation->getPlanningPeriod()->getLine()?->getName(),
            'capturedAt' => null !== $capturedAt ? self::utc($capturedAt) : null,
        ];
    }

    /**
     * @return array{stableId: string, firstName: string, lastName: string}|null
     */
    private static function holder(?DutyAssignment $assignment): ?array
    {
        return self::person($assignment?->getTeamMember()->getUser());
    }

    /**
     * @return array{stableId: string, firstName: string, lastName: string}|null
     */
    private static function person(?User $user): ?array
    {
        return null === $user ? null : [
            'stableId' => (string) $user->getStableId(),
            'firstName' => $user->getFirstName(),
            'lastName' => $user->getLastName(),
        ];
    }

    /** Every instant of the history in UTC, ISO 8601: the client shows it in Europe/Brussels. */
    private static function utc(\DateTimeImmutable $at): string
    {
        return $at->setTimezone(new \DateTimeZone('UTC'))->format(\DATE_ATOM);
    }
}
