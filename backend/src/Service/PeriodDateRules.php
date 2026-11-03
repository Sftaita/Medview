<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\PlanningCycle;
use App\Entity\PlanningLine;
use App\Entity\PlanningPeriod;
use App\Entity\PlanningPeriodStatus;
use App\Repository\PlanningCycleRepository;
use App\Repository\PlanningJobRepository;
use App\Repository\PlanningLineRepository;

/**
 * The dates a period may take (docs/decisions.md D207) — one place for the
 * rules, read the same way by a write (PlanningCycleService, which throws
 * the first violation) and by the date picker (the options endpoint, which
 * lists every violation of every day).
 *
 * - A following period always starts the day after the last day of the
 *   previous one: no gap, no overlap. Choosing its first day moves the end of
 *   the previous period with it, and the other way round.
 * - docs/decisions.md D225: since "Modifier les dates" is one action, what a
 *   date does to a generated or published period is no longer a refusal here:
 *   it is its plan (PeriodDateChangePlanner), said and confirmed before anything
 *   is written. What is left here is what no confirmation can make acceptable:
 *   an archived period, a calculation running, a period too short, a boundary
 *   that cuts a block — of the weekly structure, or one actually materialized,
 *   whatever the structure has become since — and a collection that would lie
 *   entirely outside its period.
 * - A collection is never resized silently: the plan names each one reduced,
 *   and says when people had already answered or been written to (D208).
 * - A boundary never cuts a block (lot L3) — the one exception, a historical
 *   boundary (D192), concerns the creation of a period only.
 * - The exceptional rectification of D211 keeps its own stricter rules below.
 */
final class PeriodDateRules
{
    private const LOCKED_STATUSES = [PlanningPeriodStatus::VALIDATED, PlanningPeriodStatus::PUBLISHED, PlanningPeriodStatus::ARCHIVED];

    /** The longest range of days one options request evaluates. */
    public const MAX_OPTION_DAYS = 400;

    public function __construct(
        private readonly PlanningCycleRepository $cycleRepository,
        private readonly PlanningLineRepository $lineRepository,
        private readonly PlanningJobRepository $jobRepository,
        private readonly PeriodBoundaryGuard $boundaryGuard,
    ) {
    }

    /**
     * Whether the start (the first period only) and the end of $cycle can
     * move at all now — what the period view announces (canEditStart,
     * canEditEnd); which dates exactly, the options say. The same blockers
     * as a write, nothing more.
     *
     * @return array{start: bool, end: bool}
     */
    public function editable(PlanningCycle $cycle): array
    {
        $context = $this->context($cycle, false);

        return ['start' => [] === $this->startBlockersIn($context), 'end' => [] === $this->endBlockersIn($context)];
    }

    // --- the end of an existing period -------------------------------------------------------------

    /**
     * Why the end of $cycle cannot move at all, whatever the date.
     *
     * @return list<PeriodDateViolation>
     */
    public function endBlockers(PlanningCycle $cycle): array
    {
        return $this->endBlockersIn($this->context($cycle));
    }

    /**
     * Every reason $endsAt (exclusive) is refused as the new end of $cycle —
     * blockers first, then what depends on the date.
     *
     * @return list<PeriodDateViolation>
     */
    public function endViolations(PlanningCycle $cycle, \DateTimeImmutable $endsAt, ?\DateTimeImmutable $startsAt = null): array
    {
        $context = $this->context($cycle);

        return [...$this->endBlockersIn($context), ...$this->endDateViolations($context, $endsAt, $startsAt)];
    }

    /**
     * Each last day (inclusive) from $from to $to: allowed or not, and why.
     *
     * @return array{blockers: list<PeriodDateViolation>, days: list<array{date: string, violations: list<PeriodDateViolation>}>}
     */
    public function endOptions(PlanningCycle $cycle, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $context = $this->context($cycle);

        return [
            'blockers' => $this->endBlockersIn($context),
            'days' => $this->days($from, $to, fn (\DateTimeImmutable $lastDay): array => $this->endDateViolations($context, $lastDay->modify('+1 day'))),
        ];
    }

    // --- the start of the first period -------------------------------------------------------------

    /**
     * @return list<PeriodDateViolation>
     */
    public function startBlockers(PlanningCycle $cycle): array
    {
        return $this->startBlockersIn($this->context($cycle));
    }

    /**
     * @return list<PeriodDateViolation>
     */
    public function startViolations(PlanningCycle $cycle, \DateTimeImmutable $startsAt, ?\DateTimeImmutable $endsAt = null): array
    {
        $context = $this->context($cycle);

        return [...$this->startBlockersIn($context), ...$this->startDateViolations($context, $startsAt, $endsAt)];
    }

    /**
     * @return array{blockers: list<PeriodDateViolation>, days: list<array{date: string, violations: list<PeriodDateViolation>}>}
     */
    public function startOptions(PlanningCycle $cycle, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $context = $this->context($cycle);

        return [
            'blockers' => $this->startBlockersIn($context),
            'days' => $this->days($from, $to, fn (\DateTimeImmutable $day): array => $this->startDateViolations($context, $day)),
        ];
    }

    // --- the end of the next period, on creation ----------------------------------------------------

    /**
     * What a new period after $last may end on: its start is fixed (the end
     * of $last); its end lasts MIN_DAYS at least and cuts no block. The cuts
     * at its start are reported apart (historical ones need the confirmation
     * of D192 — PlanningCycleService::createNext() — ordinary ones block).
     *
     * @return list<PeriodDateViolation>
     */
    public function nextEndViolations(PlanningCycle $last, \DateTimeImmutable $endsAt): array
    {
        return $this->nextEndDateViolations($last, $this->blocksOfLines($this->activeLinesOfPlanning($last)), $endsAt);
    }

    /**
     * @return array{blockers: list<PeriodDateViolation>, historicalStartCuts: list<BlockCut>, days: list<array{date: string, violations: list<PeriodDateViolation>}>}
     */
    public function nextEndOptions(PlanningCycle $last, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $lines = $this->activeLinesOfPlanning($last);
        [$historical, $ordinary] = $this->boundaryGuard->classifyStartCuts($last, $lines);
        $blockers = [];
        if (null !== $this->jobRepository->findActiveForPlanning($last->getPlanning())) {
            $blockers[] = self::jobInProgress();
        }
        if ([] !== $ordinary) {
            $blockers[] = PeriodDateViolation::splitsBlock($ordinary);
        }
        $blocks = $this->blocksOfLines($lines);

        return [
            'blockers' => $blockers,
            'historicalStartCuts' => $historical,
            'days' => $this->days($from, $to, fn (\DateTimeImmutable $lastDay): array => $this->nextEndDateViolations($last, $blocks, $lastDay->modify('+1 day'))),
        ];
    }

    // --- the rectification of a locked period's end (docs/decisions.md D211) ------------------------

    /**
     * Why the end of $cycle can never be rectified, whatever the day: only a
     * locked period (validated, published or archived — otherwise its dates
     * change the usual way) followed by a blank period, with no calculation
     * running.
     *
     * @return list<PeriodDateViolation>
     */
    public function rectificationBlockers(PlanningCycle $cycle): array
    {
        return $this->rectificationBlockersIn($this->context($cycle, false));
    }

    /**
     * Every reason $endsAt (exclusive) is refused as the rectified end of $cycle.
     *
     * @return list<PeriodDateViolation>
     */
    public function rectificationViolations(PlanningCycle $cycle, \DateTimeImmutable $endsAt): array
    {
        $context = $this->context($cycle);

        return [...$this->rectificationBlockersIn($context), ...$this->rectificationDateViolations($context, $endsAt)];
    }

    /**
     * The last days $cycle may be brought back to: from its last duty day (or
     * its start) to the day before its current last day.
     *
     * @return array{blockers: list<PeriodDateViolation>, lastDutyDay: ?string, currentLastDay: string, days: list<array{date: string, violations: list<PeriodDateViolation>}>}
     */
    public function rectificationOptions(PlanningCycle $cycle): array
    {
        $context = $this->context($cycle);
        $lastDutyDay = $context['facts']['lastDutyDay'];
        $currentLastDay = $cycle->getEndsAt()->modify('-1 day');
        $from = null !== $lastDutyDay ? new \DateTimeImmutable($lastDutyDay) : $cycle->getStartsAt();

        return [
            'blockers' => $this->rectificationBlockersIn($context),
            'lastDutyDay' => $lastDutyDay,
            'currentLastDay' => $currentLastDay->format('Y-m-d'),
            'days' => $from < $currentLastDay
                ? $this->days($from, $currentLastDay->modify('-1 day'), fn (\DateTimeImmutable $lastDay): array => $this->rectificationDateViolations($context, $lastDay->modify('+1 day')))
                : [],
        ];
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return list<PeriodDateViolation>
     */
    private function rectificationBlockersIn(array $context): array
    {
        if (!$context['locked']) {
            return [PeriodDateViolation::conflict('period_not_locked', 'This period is not validated or published: change its dates the usual way.')];
        }
        if (null === $context['next']) {
            return [PeriodDateViolation::conflict('no_next_period', 'Only the boundary with a next period is rectified.')];
        }
        $blockers = [];
        if ($context['jobActive']) {
            $blockers[] = self::jobInProgress();
        }
        if (!self::isBlank($context['nextLocked'], $context['nextFacts'])) {
            $blockers[] = PeriodDateViolation::conflict('next_period_not_blank', 'The boundary with the next period moves only while the next period has no generation, publication, calculation or prepared duty.');
        }

        return $blockers;
    }

    /**
     * The rectified end may only remove days without any duty of any line of
     * the period, never leave a collection of the period beyond it, and never
     * cut a block — the occurrence it would split goes whole into the next
     * period instead.
     *
     * @param array<string, mixed> $context
     *
     * @return list<PeriodDateViolation>
     */
    private function rectificationDateViolations(array $context, \DateTimeImmutable $endsAt): array
    {
        /** @var PlanningCycle $cycle */
        $cycle = $context['cycle'];
        $facts = $context['facts'];
        $end = $endsAt->format('Y-m-d');
        if ($end >= $cycle->getEndsAt()->format('Y-m-d')) {
            return [PeriodDateViolation::invalid('rectification_not_earlier', 'A rectified end comes before the current one.')];
        }
        $violations = [];
        if (!self::lastsLongEnough($cycle->getStartsAt(), $endsAt)) {
            $violations[] = self::tooShort();
        }
        if (null !== $facts['lastDutyDay'] && $facts['lastDutyDay'] >= $end) {
            $violations[] = PeriodDateViolation::conflict('period_has_duties_after', 'Duties exist on or after this day: they stay in their period.');
        }
        if (null !== $facts['lastCollectionEnd'] && $facts['lastCollectionEnd'] > $end) {
            $violations[] = self::collectionKept($facts, 'collection_beyond_end', 'An availability collection of this period goes beyond the new end.');
        }
        $cuts = $this->cutsAt($context['blocks'], $endsAt);
        if ([] !== $cuts) {
            $violations[] = PeriodDateViolation::splitsBlock($cuts);
        }

        return $violations;
    }

    // --- facts ---------------------------------------------------------------------------------------

    /**
     * @param array{generations: int, publications: int, jobs: int, duties: int} $facts
     */
    public static function isBlank(bool $locked, array $facts): bool
    {
        return !$locked && 0 === $facts['generations'] + $facts['publications'] + $facts['jobs'] + $facts['duties'];
    }

    /**
     * Everything the rules read about $cycle and its neighbours, once — the
     * blocks only when a date is checked ($withBlocks).
     *
     * @return array{cycle: PlanningCycle, previous: ?PlanningCycle, next: ?PlanningCycle, locked: bool, facts: array<string, mixed>, nextLocked: bool, nextFacts: ?array<string, mixed>, jobActive: bool, blocks: list<array{line: PlanningLine, blocks: list<array{name: string, offsets: list<int>}>}>, startBlocks: list<array{line: PlanningLine, blocks: list<array{name: string, offsets: list<int>}>}>}
     */
    private function context(PlanningCycle $cycle, bool $withBlocks = true): array
    {
        $cycles = $this->cycleRepository->findByPlanning($cycle->getPlanning());
        $index = array_search($cycle, $cycles, true);
        $previous = false !== $index && $index > 0 ? $cycles[$index - 1] : null;
        $next = false !== $index ? ($cycles[$index + 1] ?? null) : null;
        $periods = $this->cycleRepository->periodsOf($cycle);
        $nextPeriods = null !== $next ? $this->cycleRepository->periodsOf($next) : [];

        return [
            'cycle' => $cycle,
            'previous' => $previous,
            'next' => $next,
            'locked' => self::anyLocked($periods),
            'facts' => $this->cycleRepository->dateFacts($cycle),
            'nextLocked' => self::anyLocked($nextPeriods),
            'nextFacts' => null !== $next ? $this->cycleRepository->dateFacts($next) : null,
            'archived' => self::anyArchived($periods),
            'nextArchived' => self::anyArchived($nextPeriods),
            'materializedBlocks' => $withBlocks ? [...$this->cycleRepository->materializedBlocks($cycle), ...(null !== $next ? $this->cycleRepository->materializedBlocks($next) : [])] : [],
            'collectionWindows' => $withBlocks ? $this->cycleRepository->collectionWindows($cycle) : [],
            'nextCollectionWindows' => $withBlocks && null !== $next ? $this->cycleRepository->collectionWindows($next) : [],
            'jobActive' => null !== $this->jobRepository->findActiveForPlanning($cycle->getPlanning()),
            // The end is a boundary of the lines of this period and of the next one; the start, of this one.
            'blocks' => $withBlocks ? $this->blocksOfLines(self::activeLines([...$periods, ...$nextPeriods])) : [],
            'startBlocks' => $withBlocks ? $this->blocksOfLines(self::activeLines($periods)) : [],
        ];
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return list<PeriodDateViolation>
     */
    private function endBlockersIn(array $context): array
    {
        $blockers = [];
        if ($context['archived'] || $context['nextArchived']) {
            $blockers[] = self::archived();
        }
        if ($context['jobActive']) {
            $blockers[] = self::jobInProgress();
        }

        return $blockers;
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return list<PeriodDateViolation>
     */
    private function endDateViolations(array $context, \DateTimeImmutable $endsAt, ?\DateTimeImmutable $startsAt = null): array
    {
        /** @var PlanningCycle $cycle */
        $cycle = $context['cycle'];
        $startsAt ??= $cycle->getStartsAt();
        $end = $endsAt->format('Y-m-d');
        $violations = [];
        if (!self::lastsLongEnough($startsAt, $endsAt)) {
            $violations[] = self::tooShort();
        }
        $cuts = $this->cutsAt($context['blocks'], $endsAt);
        if ([] !== $cuts) {
            $violations[] = PeriodDateViolation::splitsBlock($cuts);
        }
        if (self::fallsInsideMaterializedBlock($context['materializedBlocks'], $end)) {
            $violations[] = self::materializedBlockCut();
        }
        // The intact collection follows the new dates; any other one keeps what is left of its window — if anything is.
        foreach (null === $context['facts']['untouchedCollectionId'] ? $context['collectionWindows'] : [] as $window) {
            if ($window['start'] >= $end) {
                $violations[] = PeriodDateViolation::conflict('collection_outside_period', 'An availability collection of this period would lie entirely outside it.');
                break;
            }
        }
        /** @var ?PlanningCycle $next */
        $next = $context['next'];
        if (null !== $next) {
            if (!self::lastsLongEnough($endsAt, $next->getEndsAt())) {
                $violations[] = PeriodDateViolation::invalid('next_period_too_short', \sprintf('The next period would last less than %d days.', PlanningCycleService::MIN_DAYS));
            }
            foreach (null === $context['nextFacts']['untouchedCollectionId'] ? $context['nextCollectionWindows'] : [] as $window) {
                if ($window['end'] <= $end) {
                    $violations[] = PeriodDateViolation::conflict('collection_outside_period', 'An availability collection of the next period would lie entirely outside it.');
                    break;
                }
            }
        }

        return $violations;
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return list<PeriodDateViolation>
     */
    private function startBlockersIn(array $context): array
    {
        // A following period's start IS the end of the previous one: the same boundary, the same rules.
        if (null !== $context['previous']) {
            return $this->endBlockersIn($this->context($context['previous'], false));
        }
        $blockers = [];
        if ($context['archived']) {
            $blockers[] = self::archived();
        }
        if ($context['jobActive']) {
            $blockers[] = self::jobInProgress();
        }

        return $blockers;
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return list<PeriodDateViolation>
     */
    private function startDateViolations(array $context, \DateTimeImmutable $startsAt, ?\DateTimeImmutable $endsAt = null): array
    {
        /** @var PlanningCycle $cycle */
        $cycle = $context['cycle'];
        $endsAt ??= $cycle->getEndsAt();
        $start = $startsAt->format('Y-m-d');
        /** @var ?PlanningCycle $previous */
        $previous = $context['previous'];
        if (null !== $previous) {
            $previousContext = $this->context($previous);
            $violations = $this->endDateViolations($previousContext, $startsAt);
            if (!self::lastsLongEnough($startsAt, $endsAt) && [] === array_filter($violations, static fn (PeriodDateViolation $v): bool => 'next_period_too_short' === $v->code)) {
                $violations[] = self::tooShort();
            }

            return $violations;
        }
        $violations = [];
        if (!self::lastsLongEnough($startsAt, $endsAt)) {
            $violations[] = self::tooShort();
        }
        $cuts = $this->cutsAt($context['startBlocks'], $startsAt);
        if ([] !== $cuts) {
            $violations[] = PeriodDateViolation::splitsBlock($cuts);
        }
        if (self::fallsInsideMaterializedBlock($context['materializedBlocks'], $start)) {
            $violations[] = self::materializedBlockCut();
        }
        foreach (null === $context['facts']['untouchedCollectionId'] ? $context['collectionWindows'] : [] as $window) {
            if ($window['end'] <= $start) {
                $violations[] = PeriodDateViolation::conflict('collection_outside_period', 'An availability collection of this period would lie entirely outside it.');
                break;
            }
        }

        return $violations;
    }

    /**
     * @param list<array{line: PlanningLine, blocks: list<array{name: string, offsets: list<int>}>}> $blocks
     *
     * @return list<PeriodDateViolation>
     */
    private function nextEndDateViolations(PlanningCycle $last, array $blocks, \DateTimeImmutable $endsAt): array
    {
        $violations = [];
        if (!self::lastsLongEnough($last->getEndsAt(), $endsAt)) {
            $violations[] = self::tooShort();
        }
        $cuts = $this->cutsAt($blocks, $endsAt);
        if ([] !== $cuts) {
            $violations[] = PeriodDateViolation::splitsBlock($cuts);
        }

        return $violations;
    }

    // --- helpers -----------------------------------------------------------------------------------

    /**
     * @param callable(\DateTimeImmutable): list<PeriodDateViolation> $violationsOf
     *
     * @return list<array{date: string, violations: list<PeriodDateViolation>}>
     */
    private function days(\DateTimeImmutable $from, \DateTimeImmutable $to, callable $violationsOf): array
    {
        $days = [];
        for ($day = new \DateTimeImmutable($from->format('Y-m-d')); $day <= $to; $day = $day->modify('+1 day')) {
            $days[] = ['date' => $day->format('Y-m-d'), 'violations' => $violationsOf($day)];
        }

        return $days;
    }

    /**
     * @param list<PlanningLine> $lines
     *
     * @return list<array{line: PlanningLine, blocks: list<array{name: string, offsets: list<int>}>}>
     */
    private function blocksOfLines(array $lines): array
    {
        $result = [];
        foreach ($lines as $line) {
            $result[] = ['line' => $line, 'blocks' => $this->boundaryGuard->blocksOf($line->getPlanningTeam())];
        }

        return $result;
    }

    /**
     * @param list<array{line: PlanningLine, blocks: list<array{name: string, offsets: list<int>}>}> $blocks
     *
     * @return list<BlockCut>
     */
    private function cutsAt(array $blocks, \DateTimeImmutable $boundary): array
    {
        $cuts = [];
        foreach ($blocks as $entry) {
            array_push($cuts, ...$this->boundaryGuard->cutsAt($entry['blocks'], $boundary, $entry['line']));
        }

        return $cuts;
    }

    /**
     * @return list<PlanningLine>
     */
    private function activeLinesOfPlanning(PlanningCycle $cycle): array
    {
        return array_values(array_filter($this->lineRepository->findByPlanning($cycle->getPlanning()), static fn (PlanningLine $line): bool => $line->isActive()));
    }

    /**
     * @param list<PlanningPeriod> $periods
     *
     * @return list<PlanningLine> each active line once
     */
    private static function activeLines(array $periods): array
    {
        $lines = [];
        foreach ($periods as $period) {
            $line = $period->getLine();
            if (null !== $line && $line->isActive()) {
                $lines[spl_object_id($line)] = $line;
            }
        }

        return array_values($lines);
    }

    /**
     * @param list<PlanningPeriod> $periods
     */
    private static function anyLocked(array $periods): bool
    {
        foreach ($periods as $period) {
            if (\in_array($period->getStatus(), self::LOCKED_STATUSES, true)) {
                return true;
            }
        }

        return false;
    }

    private static function lastsLongEnough(\DateTimeImmutable $startsAt, \DateTimeImmutable $endsAt): bool
    {
        return $endsAt > $startsAt && (int) $startsAt->diff($endsAt)->days >= PlanningCycleService::MIN_DAYS;
    }

    private static function tooShort(): PeriodDateViolation
    {
        return PeriodDateViolation::invalid('period_too_short', \sprintf('A period lasts at least %d days.', PlanningCycleService::MIN_DAYS));
    }

    /**
     * Why a collection keeps its window, said as precisely as known: people
     * were written to about it (a reminder — an invitation once announcements
     * exist), or answered; otherwise the generic $code.
     *
     * @param array<string, mixed> $facts
     */
    private static function collectionKept(array $facts, string $code, string $message): PeriodDateViolation
    {
        if ($facts['collectionContacted']) {
            return PeriodDateViolation::conflict('collection_announced', 'People were already written to about the availability collection of this period: its dates are not changed behind their back.');
        }
        if ($facts['collectionAnswered']) {
            return PeriodDateViolation::conflict('collection_answered', 'People already answered the availability collection of this period: its dates are not changed behind their back.');
        }

        return PeriodDateViolation::conflict($code, $message);
    }

    /**
     * @param list<array{name: string, line: string, first: string, last: string}> $blocks
     */
    private static function fallsInsideMaterializedBlock(array $blocks, string $boundary): bool
    {
        foreach ($blocks as $block) {
            if ($block['first'] < $boundary && $boundary <= $block['last']) {
                return true;
            }
        }

        return false;
    }

    private static function materializedBlockCut(): PeriodDateViolation
    {
        return PeriodDateViolation::conflict('materialized_block_crosses_boundary', 'A block of duties already created would be cut in two: a block stays whole.');
    }

    private static function archived(): PeriodDateViolation
    {
        return PeriodDateViolation::conflict('period_archived', 'An archived period never changes.');
    }

    /**
     * @param list<PlanningPeriod> $periods
     */
    private static function anyArchived(array $periods): bool
    {
        foreach ($periods as $period) {
            if (PlanningPeriodStatus::ARCHIVED === $period->getStatus()) {
                return true;
            }
        }

        return false;
    }

    private static function jobInProgress(): PeriodDateViolation
    {
        return PeriodDateViolation::conflict('job_in_progress', 'A generation or a completion is in progress on this planning.');
    }
}
