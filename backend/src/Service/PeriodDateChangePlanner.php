<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AvailabilityCollectionStatus;
use App\Entity\Duty;
use App\Entity\DutySwapRequest;
use App\Entity\PlanningCycle;
use App\Entity\PlanningLine;
use App\Entity\PlanningPeriodStatus;
use App\Repository\AvailabilityCollectionRepository;
use App\Repository\DutyAssignmentRepository;
use App\Repository\DutyRepository;
use App\Repository\DutySwapRequestRepository;
use App\Repository\PeriodPreparationReminderRepository;
use App\Repository\PeriodPreparationRepository;
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
        private readonly PeriodDateRules $dateRules,
        private readonly PeriodPreparationRepository $preparationRepository,
        private readonly PeriodPreparationReminderRepository $reminderRepository,
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
        $preparation = [];
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
            array_push($collections, ...$this->collectionsOf($moved, $target['startsAt'], $target['endsAt'], $gained));
            array_push($preparation, ...$this->preparationOf($moved, $target['endsAt'], $target['previousEndsAt']));
        }

        // docs/decisions.md D230: a collection people already answered or were written to, reduced; a date planned that
        // has to change: seen before anything is written, never only said afterwards.
        foreach ($collections as $collection) {
            if ('reduced' === $collection['kind'] && ($collection['contacted'] || $collection['answered'])) {
                $confirmationRequired = true;
            }
        }
        if ([] !== $preparation) {
            $confirmationRequired = true;
        }

        // docs/decisions.md D229: a boundary inside a block is the manager's decision, whatever the state of the period.
        [$blockCuts, $cutDecisions] = $this->cutsOf($cycle, $startsAt, $endsAt);
        if ([] !== $blockCuts || self::cutsAMaterializedBlock($retired)) {
            $confirmationRequired = true;
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
            $this->hash($targets, $retired, $republish, $preparation, $collections),
            $blockCuts,
            $cutDecisions,
            $preparation,
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
                'dutyCount' => $this->inheritedReinforcements->inherits($line) ? null : $this->weeklyCalendar->countExtension($period, $startsAt, $endsAt, $from, $to, true),
                'published' => \in_array($period->getStatus(), self::PUBLISHED_STATUSES, true),
            ];
        }

        return $additions;
    }

    /**
     * Each collection the new dates concern: reduced (with what it has lived),
     * following an intact period, or staying as it is while the period gains
     * days it does not cover. A collection is never closed by this.
     *
     * @param list<array{0: \DateTimeImmutable, 1: \DateTimeImmutable}> $gained
     *
     * @return list<array<string, mixed>>
     */
    private function collectionsOf(PlanningCycle $cycle, \DateTimeImmutable $startsAt, \DateTimeImmutable $endsAt, array $gained): array
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
            $sameWindow = self::day($newStart) === self::day($collection->getStartsAt()) && self::day($newEnd) === self::day($collection->getEndsAt());
            $addedDays = $follows ? 0 : array_sum(array_map(static fn (array $range): int => (int) $range[0]->diff($range[1])->days, $gained));
            if ($sameWindow && 0 === $addedDays) {
                continue;
            }
            $activity = $this->cycleRepository->collectionActivity((int) $collection->getId(), $cycle);
            $deadline = $collection->getDeadline();
            $lastDay = $endsAt->modify('-1 day');
            $rows[] = [
                'kind' => $sameWindow ? 'not_extended' : ($follows ? 'follows' : 'reduced'),
                'open' => AvailabilityCollectionStatus::OPEN === $collection->getStatus(),
                'expected' => $activity['expected'],
                'responses' => $activity['answered'],
                'messagesSent' => $activity['remindersSent'],
                'deadline' => null !== $deadline ? self::day($deadline) : null,
                'newDeadline' => null !== $deadline ? self::day(min($deadline, $lastDay)) : null,
                'uncoveredAddedDays' => $addedDays,
                'collectionId' => (int) $collection->getId(),
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

    /**
     * docs/decisions.md D230: what is planned for $cycle after its new last
     * day, when it ends earlier. Nothing changes for a period that grows, nor for
     * what already happened (an announcement made, a reminder sent).
     *
     * @return list<array{cycle: PlanningCycle, kind: string, from: string, to: ?string, collectionId?: int, reminderId?: int}>
     */
    private function preparationOf(PlanningCycle $cycle, \DateTimeImmutable $endsAt, \DateTimeImmutable $previousEndsAt): array
    {
        if ($endsAt >= $previousEndsAt) {
            return [];
        }
        $lastDay = $endsAt->modify('-1 day');
        $last = self::day($lastDay);
        $changes = [];
        foreach ($this->collectionRepository->findByPlanning($cycle->getPlanning()) as $collection) {
            $deadline = $collection->getDeadline();
            if ($collection->getCycle() === $cycle && null !== $deadline && self::day($deadline) > $last) {
                $changes[] = ['cycle' => $cycle, 'kind' => 'collection_deadline', 'from' => self::day($deadline), 'to' => $last, 'collectionId' => (int) $collection->getId()];
            }
        }
        $preparation = $this->preparationRepository->findOneByCycle($cycle);
        if (null === $preparation) {
            return $changes;
        }
        if ($preparation->isAnnouncementScheduled() && null !== $preparation->getAnnounceOn() && self::day($preparation->getAnnounceOn()) > $last) {
            $changes[] = ['cycle' => $cycle, 'kind' => 'announcement', 'from' => self::day($preparation->getAnnounceOn()), 'to' => null];
        }
        $deadline = $preparation->getDeclarationDeadlineOn();
        if (null !== $deadline && self::day($deadline) > $last) {
            $changes[] = ['cycle' => $cycle, 'kind' => 'deadline', 'from' => self::day($deadline), 'to' => $last];
        }
        $target = $preparation->getTargetGenerationOn();
        $targetMoves = null !== $target && self::day($target) > $last;
        if ($targetMoves) {
            $changes[] = ['cycle' => $cycle, 'kind' => 'target', 'from' => self::day($target), 'to' => $last];
        }
        $today = new \DateTimeImmutable('today');
        foreach ($this->reminderRepository->findScheduled($preparation) as $reminder) {
            $due = $reminder->getDueOn();
            if ($targetMoves) {
                // A reminder stays N days before the target; on a day already past, it can no longer be sent.
                $moved = $lastDay->modify(\sprintf('-%d days', $reminder->getOffsetUnit()->days($reminder->getOffsetValue())));
                $changes[] = ['cycle' => $cycle, 'kind' => 'reminder', 'from' => self::day($due), 'to' => $moved < $today ? null : self::day($moved), 'reminderId' => (int) $reminder->getId()];
            } elseif (self::day($due) > $last) {
                $changes[] = ['cycle' => $cycle, 'kind' => 'reminder', 'from' => self::day($due), 'to' => null, 'reminderId' => (int) $reminder->getId()];
            }
        }

        return $changes;
    }

    /**
     * What the new dates cut (structure), and the decision to record per boundary.
     *
     * @return array{0: list<BlockCut>, 1: list<array{cycle: PlanningCycle, edge: string, cut: bool}>}
     */
    private function cutsOf(PlanningCycle $cycle, ?\DateTimeImmutable $startsAt, ?\DateTimeImmutable $endsAt): array
    {
        $cuts = [];
        $decisions = [];
        if (null !== $endsAt && self::day($endsAt) !== self::day($cycle->getEndsAt())) {
            $found = $this->structureCuts($cycle, 'end', $endsAt);
            array_push($cuts, ...$found);
            $decisions[] = ['cycle' => $cycle, 'edge' => 'end', 'cut' => [] !== $found];
        }
        if (null !== $startsAt && self::day($startsAt) !== self::day($cycle->getStartsAt())) {
            $found = $this->structureCuts($cycle, 'start', $startsAt);
            array_push($cuts, ...$found);
            $cycles = $this->cycleRepository->findByPlanning($cycle->getPlanning());
            $index = array_search($cycle, $cycles, true);
            $previous = false !== $index && $index > 0 ? $cycles[$index - 1] : null;
            $decisions[] = null !== $previous
                ? ['cycle' => $previous, 'edge' => 'end', 'cut' => [] !== $found]
                : ['cycle' => $cycle, 'edge' => 'start', 'cut' => [] !== $found];
        }

        return [$cuts, $decisions];
    }

    /**
     * @return list<BlockCut>
     */
    private function structureCuts(PlanningCycle $cycle, string $edge, \DateTimeImmutable $boundary): array
    {
        $cuts = [];
        foreach ($this->dateRules->cutWarnings($cycle, $edge, $boundary) as $warning) {
            array_push($cuts, ...$warning->cuts);
        }

        return $cuts;
    }

    /**
     * @param list<array{duty: Duty}> $retired
     */
    private static function cutsAMaterializedBlock(array $retired): bool
    {
        $retiredIds = [];
        foreach ($retired as $row) {
            $retiredIds[(int) $row['duty']->getId()] = true;
        }
        foreach ($retired as $row) {
            foreach ($row['duty']->getGroupInstance()?->getActiveDuties() ?? [] as $other) {
                if (!isset($retiredIds[(int) $other->getId()])) {
                    return true;
                }
            }
        }

        return false;
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
     * @param list<array<string, mixed>>                                                                  $preparation
     * @param list<array<string, mixed>>                                                                  $collections
     */
    private function hash(array $targets, array $retired, array $republish, array $preparation = [], array $collections = []): string
    {
        return hash('sha256', (string) json_encode([
            array_map(static fn (array $t): array => [$t['cycle']->getId(), self::day($t['startsAt']), self::day($t['endsAt'])], $targets),
            array_map(static fn (array $r): array => [$r['duty']->getId(), $r['assignment']?->getId()], $retired),
            array_map(fn (PlanningCycle $c): ?int => $this->publicationRepository->findLatestForCycle($c)?->getId(), array_values($republish)),
            array_map(static fn (array $p): array => [$p['kind'], $p['from'], $p['to']], $preparation),
            array_map(static fn (array $c): array => [$c['collectionId'], $c['newStartsAt'], $c['newEndsAt'], $c['responses'], $c['messagesSent']], $collections),
        ]));
    }

    private static function day(\DateTimeImmutable $date): string
    {
        return $date->format('Y-m-d');
    }
}
