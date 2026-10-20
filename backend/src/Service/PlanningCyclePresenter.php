<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AvailabilityCollection;
use App\Entity\Planning;
use App\Entity\PlanningCycle;
use App\Entity\PlanningLine;
use App\Entity\PlanningPeriod;
use App\Entity\PlanningPeriodStatus;
use App\Repository\AvailabilityCollectionRepository;
use App\Repository\PlanningCycleRepository;

/**
 * The API view of the periods of a Planning (docs/planning-cycles.md §4,
 * §7.2). A period's status is derived here, never stored (I13), from the
 * periods of the lines that COUNT in it (LineApplicability: applicable or
 * incomplete — a line with no demand in the period is neutral,
 * docs/decisions.md D194) and its collections:
 *
 * | status                | first condition that holds                                  |
 * |-----------------------|-------------------------------------------------------------|
 * | `published`           | every counting line period PUBLISHED (or ARCHIVED)          |
 * | `partially_published` | some counting line periods PUBLISHED, not all               |
 * | `generated`           | every counting line period at least GENERATED               |
 * | `collecting`          | a collection of the period is OPEN                          |
 * | `preparation`         | otherwise — also when no line counts at all: a period is    |
 * |                       | never "published" without a line actually published         |
 *
 * This is the CURRENT state: a publication keeps its own (lot L4c).
 */
final class PlanningCyclePresenter
{
    private const GENERATED_OR_LATER = [PlanningPeriodStatus::GENERATED, PlanningPeriodStatus::VALIDATED, PlanningPeriodStatus::PUBLISHED, PlanningPeriodStatus::ARCHIVED];
    private const LOCKED = [PlanningPeriodStatus::VALIDATED, PlanningPeriodStatus::PUBLISHED, PlanningPeriodStatus::ARCHIVED];
    /** What a planning card reads of a period (§7.1, lot L6). */
    private const SUMMARY_FIELDS = ['stableId', 'number', 'startsAt', 'endsAt', 'lastDay', 'status', 'lineCounts', 'timeState', 'publishedAt', 'lastPublishedAt', 'generatedAt', 'collections'];

    public function __construct(
        private readonly PlanningCycleRepository $cycleRepository,
        private readonly AvailabilityCollectionRepository $collectionRepository,
        private readonly AvailabilityCollectionService $collectionService,
        private readonly PlanningCycleService $cycleService,
        private readonly PlanningClock $clock,
        private readonly LineApplicabilityService $applicability,
    ) {
    }

    /**
     * @return list<array<string, mixed>> every period of $planning, in order
     */
    public function periods(Planning $planning): array
    {
        $cycles = $this->cycleRepository->findByPlanning($planning);
        $today = $this->clock->today($planning);
        $collections = $this->collectionRepository->findByPlanning($planning);

        return array_map(fn (PlanningCycle $cycle): array => $this->toArray($cycle, $cycles, $today, $collections), $cycles);
    }

    /**
     * @return array<string, mixed>
     */
    public function period(PlanningCycle $cycle): array
    {
        $planning = $cycle->getPlanning();

        return $this->toArray($cycle, $this->cycleRepository->findByPlanning($planning), $this->clock->today($planning), $this->collectionRepository->findByPlanning($planning));
    }

    /**
     * What every period-scoped response carries, so that a client drops an
     * answer that no longer matches the period on screen (§7).
     *
     * @return array{stableId: string, number: int}
     */
    public function reference(PlanningCycle $cycle): array
    {
        return ['stableId' => (string) $cycle->getStableId(), 'number' => $cycle->getNumber()];
    }

    /**
     * How many periods $planning has — a screen names "Période N" only once
     * there are several (lot L7: "Mes gardes", swaps).
     */
    public function periodCount(Planning $planning): int
    {
        return \count($this->cycleRepository->findByPlanning($planning));
    }

    /** The current period, else the next one, else the last (§4.1, §7). */
    public function defaultCycle(Planning $planning): PlanningCycle
    {
        $cycles = $this->cycleRepository->findByPlanning($planning);
        if ([] === $cycles) {
            throw new \LogicException('Every Planning has its cycle 1 (docs/planning-cycles.md I1).');
        }

        return $this->clock->defaultOf($cycles, $this->clock->today($planning));
    }

    /**
     * §4.1 / §7.1: the facts a planning card and the detail page read. The
     * current, next and latest periods carry their own status, time and
     * collection progress (lot L6): a planning card shows the status of each
     * period, never one global "published" (D194).
     *
     * @return array{today: string, currentPeriod: ?array<string, mixed>, nextPeriod: ?array<string, mixed>, latestPeriod: array<string, mixed>, periodCount: int, currentPeriodStableId: ?string, nextPeriodStableId: ?string}
     */
    public function overview(Planning $planning): array
    {
        $cycles = $this->cycleRepository->findByPlanning($planning);
        $today = $this->clock->today($planning);
        $current = $this->clock->current($cycles, $today);
        $next = $this->clock->next($cycles, $today);
        $collections = $this->collectionRepository->findByPlanning($planning);
        $summary = fn (PlanningCycle $cycle): array => array_intersect_key(
            $this->toArray($cycle, $cycles, $today, $collections),
            array_flip(self::SUMMARY_FIELDS),
        );
        $latest = $cycles[\count($cycles) - 1] ?? throw new \LogicException('Every Planning has its cycle 1 (docs/planning-cycles.md I1).');

        return [
            'today' => $today->format('Y-m-d'),
            'currentPeriod' => null !== $current ? $summary($current) : null,
            'nextPeriod' => null !== $next ? $summary($next) : null,
            // The last period: what a card shows once every period is over.
            'latestPeriod' => $summary($latest),
            'periodCount' => \count($cycles),
            'currentPeriodStableId' => null !== $current ? (string) $current->getStableId() : null,
            'nextPeriodStableId' => null !== $next ? (string) $next->getStableId() : null,
        ];
    }

    /**
     * A line's periods, and its (deprecated, §7.1) single fields for the
     * default period: null when the line takes no part in it.
     *
     * @return array{planningPeriodStableId: ?string, periodStatus: ?string, periods: list<array{periodStableId: string, number: int, planningPeriodStableId: string, status: string}>}
     */
    public function lineView(PlanningLine $line, PlanningCycle $defaultCycle): array
    {
        $default = $line->periodIn($defaultCycle);

        return [
            'planningPeriodStableId' => null !== $default ? (string) $default->getStableId() : null,
            'periodStatus' => $default?->getStatus()->value,
            'periods' => array_values(array_map(static fn (PlanningPeriod $period): array => [
                'periodStableId' => (string) $period->getCycle()?->getStableId(),
                'number' => (int) $period->getCycle()?->getNumber(),
                'planningPeriodStableId' => (string) $period->getStableId(),
                'status' => $period->getStatus()->value,
            ], array_filter($line->getPeriods(), static fn (PlanningPeriod $period): bool => null !== $period->getCycle()))),
        ];
    }

    /**
     * @param list<PlanningCycle>          $cycles
     * @param list<AvailabilityCollection> $collections every collection of the Planning
     *
     * @return array<string, mixed>
     */
    private function toArray(PlanningCycle $cycle, array $cycles, \DateTimeImmutable $today, array $collections): array
    {
        $periods = array_values(array_filter(
            $this->cycleRepository->periodsOf($cycle),
            static fn (PlanningPeriod $period): bool => true === $period->getLine()?->isActive(),
        ));
        $own = array_values(array_filter($collections, static fn (AvailabilityCollection $collection): bool => $collection->getCycle() === $cycle));
        $open = array_values(array_filter($own, static fn (AvailabilityCollection $collection): bool => $collection->isOpen()));

        $expected = 0;
        $acknowledged = 0;
        $deadline = null;
        foreach ($open as $collection) {
            $progress = $this->collectionService->progress($collection);
            $expected += $progress['expected'];
            $acknowledged += $progress['acknowledged'];
            $collectionDeadline = $collection->getDeadline()?->format('Y-m-d');
            if (null !== $collectionDeadline && (null === $deadline || $collectionDeadline > $deadline)) {
                $deadline = $collectionDeadline;
            }
        }

        $applicability = [];
        foreach ($periods as $period) {
            $line = $period->getLine();
            if (null !== $line) {
                $applicability[(int) $period->getId()] = $this->applicability->forLine($line, $cycle);
            }
        }
        $counting = array_values(array_filter($periods, static fn (PlanningPeriod $period): bool => true === ($applicability[(int) $period->getId()] ?? null)?->counts()));

        $isLast = $cycles[\count($cycles) - 1] === $cycle;
        $locked = [] !== array_filter($periods, static fn (PlanningPeriod $period): bool => \in_array($period->getStatus(), self::LOCKED, true));
        $enabled = $this->cycleService->isCreationEnabled();

        return [
            ...$this->reference($cycle),
            'startsAt' => $cycle->getStartsAt()->format('Y-m-d'),
            'endsAt' => $cycle->getEndsAt()->format('Y-m-d'),
            'lastDay' => $cycle->getEndsAt()->modify('-1 day')->format('Y-m-d'),
            'status' => $this->status($counting, [] !== $open),
            // docs/decisions.md D194: how many lines count, how many of them are published, how many are neutral.
            'lineCounts' => [
                'counting' => \count($counting),
                'published' => \count(array_filter($counting, static fn (PlanningPeriod $period): bool => \in_array($period->getStatus(), [PlanningPeriodStatus::PUBLISHED, PlanningPeriodStatus::ARCHIVED], true))),
                'incomplete' => \count(array_filter($applicability, static fn (LineApplicability $a): bool => LineApplicability::INCOMPLETE === $a->state)),
                'withoutDemand' => \count(array_filter($applicability, static fn (LineApplicability $a): bool => LineApplicability::NO_DEMAND === $a->state)),
            ],
            'timeState' => $this->clock->timeState($cycle, $today),
            ...$this->cycleRepository->milestones($cycle),
            'lines' => array_map(static fn (PlanningPeriod $period): array => [
                'lineStableId' => (string) $period->getLine()?->getStableId(),
                'planningPeriodStableId' => (string) $period->getStableId(),
                'status' => $period->getStatus()->value,
                'applicability' => ($applicability[(int) $period->getId()] ?? null)?->toArray(),
            ], $periods),
            'collections' => [
                'open' => \count($open),
                'closed' => \count($own) - \count($open),
                'acknowledged' => $acknowledged,
                'expected' => $expected,
                'deadline' => $deadline,
                'uncoveredDays' => $this->cycleService->uncoveredDays($cycle),
            ],
            'isLast' => $isLast,
            'canEditEnd' => $enabled && $isLast && !$locked,
            'canDelete' => $enabled && $isLast && $cycle->getNumber() > 1 && [] === $own
                && 0 === $this->cycleRepository->countGenerations($cycle)
                && 0 === $this->cycleRepository->countPublications($cycle)
                && 0 === $this->cycleRepository->countJobs($cycle),
        ];
    }

    /**
     * @param list<PlanningPeriod> $periods the periods of the lines that count in the cycle (D194)
     */
    private function status(array $periods, bool $collecting): string
    {
        if ([] !== $periods) {
            $statuses = array_map(static fn (PlanningPeriod $period): PlanningPeriodStatus => $period->getStatus(), $periods);
            $published = array_filter($statuses, static fn (PlanningPeriodStatus $status): bool => PlanningPeriodStatus::PUBLISHED === $status || PlanningPeriodStatus::ARCHIVED === $status);
            if (\count($published) === \count($statuses)) {
                return 'published';
            }
            if ([] !== $published) {
                return 'partially_published';
            }
            if ([] === array_filter($statuses, static fn (PlanningPeriodStatus $status): bool => !\in_array($status, self::GENERATED_OR_LATER, true))) {
                return 'generated';
            }
        }

        return $collecting ? 'collecting' : 'preparation';
    }
}
