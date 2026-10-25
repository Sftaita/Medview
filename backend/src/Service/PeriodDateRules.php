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
 *   previous one: no gap, no overlap. Its start moves only with the end of
 *   the previous period.
 * - The first period's start moves while it is blank.
 * - The end of the last period moves as before (docs/planning-cycles.md
 *   §5.2): it grows freely, shrinks while nothing lies beyond.
 * - The boundary between two periods moves only while both are blank: no
 *   line period validated, published or archived, no generation, no
 *   publication, no calculation, no duty prepared.
 * - A boundary never cuts a block (lot L3) — the one exception, a historical
 *   boundary (D192), is the start of a period after a locked one, which by
 *   definition never moves.
 * - Collections bound the dates: a new end never leaves one outside, a new
 *   start never either — except the intact collection of a blank period
 *   (PlanningCycleRepository::dateFacts(): the only one, open, covering it
 *   exactly, nobody answered, changed, withdrew or was written to), which
 *   follows the new dates. A collection people were written to, or answered,
 *   is never resized silently: the refusal says so (collection_announced,
 *   collection_answered).
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
    public function endViolations(PlanningCycle $cycle, \DateTimeImmutable $endsAt): array
    {
        $context = $this->context($cycle);

        return [...$this->endBlockersIn($context), ...$this->endDateViolations($context, $endsAt)];
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
    public function startViolations(PlanningCycle $cycle, \DateTimeImmutable $startsAt): array
    {
        $context = $this->context($cycle);

        return [...$this->startBlockersIn($context), ...$this->startDateViolations($context, $startsAt)];
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
        if ($context['locked']) {
            $blockers[] = PeriodDateViolation::conflict('period_locked', 'This period is validated or published: its dates no longer change.');
        }
        if ($context['jobActive']) {
            $blockers[] = self::jobInProgress();
        }
        if (null !== $context['next']) {
            if (!$context['locked'] && !self::isBlank(false, $context['facts'])) {
                $blockers[] = PeriodDateViolation::conflict('period_not_blank', 'The boundary with the next period moves only while this period has no generation, publication, calculation or prepared duty.');
            }
            if (!self::isBlank($context['nextLocked'], $context['nextFacts'])) {
                $blockers[] = PeriodDateViolation::conflict('next_period_not_blank', 'The boundary with the next period moves only while the next period has no generation, publication, calculation or prepared duty.');
            }
        }

        return $blockers;
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return list<PeriodDateViolation>
     */
    private function endDateViolations(array $context, \DateTimeImmutable $endsAt): array
    {
        /** @var PlanningCycle $cycle */
        $cycle = $context['cycle'];
        $facts = $context['facts'];
        $end = $endsAt->format('Y-m-d');
        $violations = [];
        if (!self::lastsLongEnough($cycle->getStartsAt(), $endsAt)) {
            $violations[] = self::tooShort();
        }
        $cuts = $this->cutsAt($context['blocks'], $endsAt);
        if ([] !== $cuts) {
            $violations[] = PeriodDateViolation::splitsBlock($cuts);
        }
        if ($end < $cycle->getEndsAt()->format('Y-m-d')) {
            if ($facts['generations'] > 0) {
                $violations[] = PeriodDateViolation::conflict('period_has_generation', 'This period was already generated: it can no longer be shortened.');
            }
            if (null !== $facts['lastDutyDay'] && $facts['lastDutyDay'] >= $end) {
                $violations[] = PeriodDateViolation::conflict('period_has_duties_after', 'Duties already exist after the new end.');
            }
            if (null === $facts['untouchedCollectionId'] && null !== $facts['lastCollectionEnd'] && $facts['lastCollectionEnd'] > $end) {
                $violations[] = self::collectionKept($facts, 'collection_beyond_end', 'An availability collection of this period goes beyond the new end.');
            }
        }
        /** @var ?PlanningCycle $next */
        $next = $context['next'];
        if (null !== $next) {
            if (!self::lastsLongEnough($endsAt, $next->getEndsAt())) {
                $violations[] = PeriodDateViolation::invalid('next_period_too_short', \sprintf('The next period would last less than %d days.', PlanningCycleService::MIN_DAYS));
            }
            $nextFacts = $context['nextFacts'];
            if ($end > $cycle->getEndsAt()->format('Y-m-d') && null === $nextFacts['untouchedCollectionId'] && null !== $nextFacts['firstCollectionStart'] && $nextFacts['firstCollectionStart'] < $end) {
                $violations[] = self::collectionKept($nextFacts, 'collection_before_start', 'An availability collection of the next period starts before the new boundary.');
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
        if (null !== $context['previous']) {
            return [PeriodDateViolation::conflict('period_start_follows_previous', 'A following period starts the day after the last day of the previous one: change the end of the previous period instead.')];
        }
        $blockers = [];
        if ($context['locked']) {
            $blockers[] = PeriodDateViolation::conflict('period_locked', 'This period is validated or published: its dates no longer change.');
        }
        if ($context['jobActive']) {
            $blockers[] = self::jobInProgress();
        }
        if (!$context['locked'] && !self::isBlank(false, $context['facts'])) {
            $blockers[] = PeriodDateViolation::conflict('period_not_blank', 'The start of the first period moves only while it has no generation, publication, calculation or prepared duty.');
        }

        return $blockers;
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return list<PeriodDateViolation>
     */
    private function startDateViolations(array $context, \DateTimeImmutable $startsAt): array
    {
        /** @var PlanningCycle $cycle */
        $cycle = $context['cycle'];
        $facts = $context['facts'];
        $start = $startsAt->format('Y-m-d');
        $violations = [];
        if (!self::lastsLongEnough($startsAt, $cycle->getEndsAt())) {
            $violations[] = self::tooShort();
        }
        $cuts = $this->cutsAt($context['startBlocks'], $startsAt);
        if ([] !== $cuts) {
            $violations[] = PeriodDateViolation::splitsBlock($cuts);
        }
        if ($start > $cycle->getStartsAt()->format('Y-m-d')) {
            if (null !== $facts['firstDutyDay'] && $facts['firstDutyDay'] < $start) {
                $violations[] = PeriodDateViolation::conflict('period_has_duties_before', 'Duties already exist before the new start.');
            }
            if (null === $facts['untouchedCollectionId'] && null !== $facts['firstCollectionStart'] && $facts['firstCollectionStart'] < $start) {
                $violations[] = self::collectionKept($facts, 'collection_before_start', 'An availability collection of this period starts before the new start.');
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

    private static function jobInProgress(): PeriodDateViolation
    {
        return PeriodDateViolation::conflict('job_in_progress', 'A generation or a completion is in progress on this planning.');
    }
}
