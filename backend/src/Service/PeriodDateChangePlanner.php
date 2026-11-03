<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Duty;
use App\Entity\DutySwapRequest;
use App\Entity\PlanningCycle;
use App\Entity\PlanningLine;
use App\Entity\PlanningPeriodStatus;
use App\Repository\AvailabilityCollectionRepository;
use App\Repository\DutyAssignmentRepository;
use App\Repository\DutyRepository;
use App\Repository\DutySwapRequestRepository;
use App\Repository\PlanningCycleRepository;
use App\Repository\PlanningGenerationRepository;
use App\Repository\PlanningPublicationRepository;

/**
 * Computes what new dates for a period would do (docs/decisions.md D225),
 * reading only: which periods move (a following period always starts the day
 * after the previous one, so the neighbour moves with it), which duties would
 * fall outside their period and who holds them, which days a generated line
 * would gain, which collections would be reduced, and whether the people
 * concerned can be told.
 *
 * Whether a confirmation is needed follows the state of the periods, never the
 * screen: changing a period with no calendar asks nothing; extending a generated
 * period onto a blank neighbour asks nothing either, the new days are simply
 * announced; shortening a generated period, or moving a generated neighbour,
 * always asks — even when no duty is on the days concerned.
 */
final class PeriodDateChangePlanner
{
    private const PUBLISHED_STATUSES = [PlanningPeriodStatus::VALIDATED, PlanningPeriodStatus::PUBLISHED, PlanningPeriodStatus::ARCHIVED];

    public function __construct(
        private readonly PlanningCycleRepository $cycleRepository,
        private readonly PlanningLineOrder $lineOrder,
        private readonly PlanningGenerationRepository $generationRepository,
        private readonly DutyAssignmentRepository $assignmentRepository,
        private readonly DutyRepository $dutyRepository,
        private readonly WeeklyDutyCalendarService $weeklyCalendar,
        private readonly InheritedReinforcementService $inheritedReinforcements,
        private readonly AvailabilityCollectionRepository $collectionRepository,
        private readonly DutySwapRequestRepository $swapRequestRepository,
        private readonly PlanningPublicationRepository $publicationRepository,
        private readonly PublicationChangeService $changeService,
        private readonly CurrentCalendarReader $calendarReader,
        private readonly PlanningPublicationPreflightService $publicationPreflight,
    ) {
    }

    /**
     * @param \DateTimeImmutable|null $startsAt the new first day of $cycle (null: unchanged)
     * @param \DateTimeImmutable|null $endsAt   the new end of $cycle, exclusive (null: unchanged)
     */
    public function plan(PlanningCycle $cycle, ?\DateTimeImmutable $startsAt, ?\DateTimeImmutable $endsAt): PeriodDateChangePlan
    {
        $targets = $this->targets($cycle, $startsAt ?? $cycle->getStartsAt(), $endsAt ?? $cycle->getEndsAt());

        $retired = [];
        $additions = [];
        $collections = [];
        $confirmationRequired = false;
        foreach ($targets as $target) {
            $moved = $target['cycle'];
            $gained = self::gainedRanges($target);
            $loses = [] !== self::lostRanges($target);
            // Shortening a generated period, or touching a generated neighbour: always said and confirmed.
            if ($target['generated'] && ($loses || !$target['selected'])) {
                $confirmationRequired = true;
            }
            foreach ($this->lineOrder->activeInCycle($moved) as $line) {
                array_push($retired, ...$this->retiredOf($moved, $line, $target['startsAt'], $target['endsAt']));
                array_push($additions, ...$this->additionsOf($moved, $line, $target['startsAt'], $target['endsAt'], $gained));
            }
            array_push($collections, ...$this->collectionsOf($moved, $target['startsAt'], $target['endsAt']));
        }

        $republish = [];
        $recipients = [];
        $pending = [];
        foreach ($retired as $row) {
            if ($row['published'] && null !== $row['assignment']) {
                $republish[$row['cycle']->getNumber()] = $row['cycle'];
                $recipients[(int) $row['assignment']->getTeamMember()->getUser()->getId()] = trim($row['assignment']->getTeamMember()->getUser()->getFirstName().' '.$row['assignment']->getTeamMember()->getUser()->getLastName());
            }
        }
        ksort($republish);
        sort($recipients);
        $notifyBlock = [] === $republish ? PeriodDateChangePlan::NOTIFY_NOBODY : null;
        foreach ($republish as $number => $moved) {
            $pending[$number] = $this->pendingChanges($moved);
            if ($pending[$number] > 0) {
                $notifyBlock ??= PeriodDateChangePlan::NOTIFY_PENDING_CHANGES;
            }
            if (!$this->publicationPreflight->check($moved->getPlanning(), $moved)->republishable) {
                $notifyBlock ??= PeriodDateChangePlan::NOTIFY_NOT_REPUBLISHABLE;
            }
        }

        return new PeriodDateChangePlan(
            $targets,
            $retired,
            $additions,
            $collections,
            $this->openSwapRequestsOn($cycle, $retired),
            $confirmationRequired,
            array_values($republish),
            array_values($recipients),
            $notifyBlock,
            $pending,
            $this->hash($targets, $retired, $republish),
        );
    }

    /**
     * @return list<array{cycle: PlanningCycle, previousStartsAt: \DateTimeImmutable, previousEndsAt: \DateTimeImmutable, startsAt: \DateTimeImmutable, endsAt: \DateTimeImmutable, generated: bool, published: bool, selected: bool}>
     */
    private function targets(PlanningCycle $cycle, \DateTimeImmutable $startsAt, \DateTimeImmutable $endsAt): array
    {
        $cycles = $this->cycleRepository->findByPlanning($cycle->getPlanning());
        $index = array_search($cycle, $cycles, true);
        $previous = false !== $index && $index > 0 ? $cycles[$index - 1] : null;
        $next = false !== $index ? ($cycles[$index + 1] ?? null) : null;

        $targets = [];
        if (null !== $previous && self::day($startsAt) !== self::day($cycle->getStartsAt())) {
            $targets[] = $this->target($previous, $previous->getStartsAt(), $startsAt, false);
        }
        if (self::day($startsAt) !== self::day($cycle->getStartsAt()) || self::day($endsAt) !== self::day($cycle->getEndsAt())) {
            $targets[] = $this->target($cycle, $startsAt, $endsAt, true);
        }
        if (null !== $next && self::day($endsAt) !== self::day($cycle->getEndsAt())) {
            $targets[] = $this->target($next, $endsAt, $next->getEndsAt(), false);
        }

        return $targets;
    }

    /**
     * @return array{cycle: PlanningCycle, previousStartsAt: \DateTimeImmutable, previousEndsAt: \DateTimeImmutable, startsAt: \DateTimeImmutable, endsAt: \DateTimeImmutable, generated: bool, published: bool, selected: bool}
     */
    private function target(PlanningCycle $cycle, \DateTimeImmutable $startsAt, \DateTimeImmutable $endsAt, bool $selected): array
    {
        $published = false;
        foreach ($this->cycleRepository->periodsOf($cycle) as $period) {
            $published = $published || \in_array($period->getStatus(), self::PUBLISHED_STATUSES, true);
        }

        return [
            'cycle' => $cycle,
            'previousStartsAt' => $cycle->getStartsAt(),
            'previousEndsAt' => $cycle->getEndsAt(),
            'startsAt' => $startsAt,
            'endsAt' => $endsAt,
            'generated' => $published || $this->cycleRepository->hasCompletedGeneration($cycle),
            'published' => $published,
            'selected' => $selected,
        ];
    }

    /**
     * The days a target gains, each range [from, to[.
     *
     * @param array{previousStartsAt: \DateTimeImmutable, previousEndsAt: \DateTimeImmutable, startsAt: \DateTimeImmutable, endsAt: \DateTimeImmutable} $target
     *
     * @return list<array{0: \DateTimeImmutable, 1: \DateTimeImmutable}>
     */
    public static function gainedRanges(array $target): array
    {
        $ranges = [];
        if ($target['startsAt'] < $target['previousStartsAt']) {
            $ranges[] = [$target['startsAt'], min($target['previousStartsAt'], $target['endsAt'])];
        }
        if ($target['endsAt'] > $target['previousEndsAt']) {
            $ranges[] = [max($target['previousEndsAt'], $target['startsAt']), $target['endsAt']];
        }

        return $ranges;
    }

    /**
     * @param array{previousStartsAt: \DateTimeImmutable, previousEndsAt: \DateTimeImmutable, startsAt: \DateTimeImmutable, endsAt: \DateTimeImmutable} $target
     *
     * @return list<array{0: \DateTimeImmutable, 1: \DateTimeImmutable}>
     */
    private static function lostRanges(array $target): array
    {
        $ranges = [];
        if ($target['startsAt'] > $target['previousStartsAt']) {
            $ranges[] = [$target['previousStartsAt'], $target['startsAt']];
        }
        if ($target['endsAt'] < $target['previousEndsAt']) {
            $ranges[] = [$target['endsAt'], $target['previousEndsAt']];
        }

        return $ranges;
    }

    /**
     * @return list<array{cycle: PlanningCycle, line: PlanningLine, duty: Duty, assignment: ?\App\Entity\DutyAssignment, published: bool}>
     */
    private function retiredOf(PlanningCycle $cycle, PlanningLine $line, \DateTimeImmutable $startsAt, \DateTimeImmutable $endsAt): array
    {
        $period = $line->periodIn($cycle);
        if (null === $period) {
            return [];
        }
        $duties = $this->dutyRepository->findOutside($period, $startsAt, $endsAt);
        if ([] === $duties) {
            return [];
        }
        $generation = $this->generationRepository->findMostRecentCompletedByPlanningPeriod($period);
        $current = [];
        foreach (null !== $generation ? $this->assignmentRepository->findForGenerations([$generation]) : [] as $assignment) {
            $current[(int) $assignment->getDuty()->getId()] = $assignment;
        }
        $published = \in_array($period->getStatus(), self::PUBLISHED_STATUSES, true);

        return array_map(static fn (Duty $duty): array => [
            'cycle' => $cycle,
            'line' => $line,
            'duty' => $duty,
            'assignment' => $current[(int) $duty->getId()] ?? null,
            'published' => $published,
        ], $duties);
    }

    /**
     * @param list<array{0: \DateTimeImmutable, 1: \DateTimeImmutable}> $gained
     *
     * @return list<array{cycle: PlanningCycle, line: PlanningLine, from: \DateTimeImmutable, to: \DateTimeImmutable, dutyCount: ?int, published: bool}>
     */
    private function additionsOf(PlanningCycle $cycle, PlanningLine $line, \DateTimeImmutable $startsAt, \DateTimeImmutable $endsAt, array $gained): array
    {
        $period = $line->periodIn($cycle);
        if (null === $period || [] === $gained || null === $this->generationRepository->findMostRecentCompletedByPlanningPeriod($period)) {
            return [];
        }
        $additions = [];
        foreach ($gained as [$from, $to]) {
            $additions[] = [
                'cycle' => $cycle,
                'line' => $line,
                'from' => $from,
                'to' => $to,
                'dutyCount' => $this->inheritedReinforcements->inherits($line) ? null : $this->weeklyCalendar->countExtension($period, $startsAt, $endsAt, $from, $to),
                'published' => \in_array($period->getStatus(), self::PUBLISHED_STATUSES, true),
            ];
        }

        return $additions;
    }

    /**
     * @return list<array{cycle: PlanningCycle, startsAt: string, endsAt: string, newStartsAt: string, newEndsAt: string, contacted: bool, answered: bool}>
     */
    private function collectionsOf(PlanningCycle $cycle, \DateTimeImmutable $startsAt, \DateTimeImmutable $endsAt): array
    {
        $facts = $this->cycleRepository->dateFacts($cycle);
        $rows = [];
        foreach ($this->collectionRepository->findByPlanning($cycle->getPlanning()) as $collection) {
            if ($collection->getCycle() !== $cycle) {
                continue;
            }
            $follows = (int) $collection->getId() === $facts['untouchedCollectionId'];
            $newStart = $follows ? $startsAt : max($collection->getStartsAt(), $startsAt);
            $newEnd = $follows ? $endsAt : min($collection->getEndsAt(), $endsAt);
            if (self::day($newStart) === self::day($collection->getStartsAt()) && self::day($newEnd) === self::day($collection->getEndsAt())) {
                continue;
            }
            $rows[] = [
                'cycle' => $cycle,
                'startsAt' => self::day($collection->getStartsAt()),
                'endsAt' => self::day($collection->getEndsAt()),
                'newStartsAt' => self::day($newStart),
                'newEndsAt' => self::day($newEnd),
                'contacted' => $facts['collectionContacted'],
                'answered' => $facts['collectionAnswered'],
            ];
        }

        return $rows;
    }

    private function pendingChanges(PlanningCycle $cycle): int
    {
        $reference = $this->publicationRepository->findLatestForCycle($cycle);
        if (null === $reference) {
            return 0;
        }
        $cells = $this->calendarReader->read(
            $cycle->getPlanning(),
            static fn (PlanningLine $line): bool => PlanningPeriodStatus::PUBLISHED === $line->periodIn($cycle)?->getStatus(),
            $cycle,
        );

        return \count($this->changeService->changesSince($reference, $cells));
    }

    /**
     * @param list<array{duty: Duty}> $retired
     */
    private function openSwapRequestsOn(PlanningCycle $cycle, array $retired): int
    {
        if ([] === $retired) {
            return 0;
        }
        $retiredIds = [];
        foreach ($retired as $row) {
            $retiredIds[(int) $row['duty']->getId()] = true;
        }

        return \count(array_filter(
            $this->swapRequestRepository->findByPlanning($cycle->getPlanning()),
            static fn (DutySwapRequest $request): bool => $request->isOpen() && isset($retiredIds[(int) $request->getOfferedDuty()->getId()]),
        ));
    }

    /**
     * @param list<array{cycle: PlanningCycle, startsAt: \DateTimeImmutable, endsAt: \DateTimeImmutable}> $targets
     * @param list<array{duty: Duty, assignment: ?\App\Entity\DutyAssignment}>                            $retired
     * @param array<int, PlanningCycle>                                                                   $republish
     */
    private function hash(array $targets, array $retired, array $republish): string
    {
        return hash('sha256', (string) json_encode([
            array_map(static fn (array $t): array => [$t['cycle']->getId(), self::day($t['startsAt']), self::day($t['endsAt'])], $targets),
            array_map(static fn (array $r): array => [$r['duty']->getId(), $r['assignment']?->getId()], $retired),
            array_map(fn (PlanningCycle $c): ?int => $this->publicationRepository->findLatestForCycle($c)?->getId(), array_values($republish)),
        ]));
    }

    private static function day(\DateTimeImmutable $date): string
    {
        return $date->format('Y-m-d');
    }
}
