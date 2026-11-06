<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Planning;
use App\Entity\PlanningCycle;
use App\Entity\PlanningGenerationStatus;
use App\Entity\PlanningLine;
use App\Entity\PlanningPeriodStatus;
use App\Entity\RestPolicyOptions;
use App\Entity\User;
use App\Exception\PlanningCompletionStaleException;
use App\Exception\PlanningGenerationInProgressException;
use App\Exception\PlanningNotLaunchableException;
use App\Repository\PlanningGenerationRepository;
use App\Repository\SolverParameterSetRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * "Générer les postes non pourvus" and "Générer cette ligne"
 * (docs/decisions.md D222): give every selected line what it is missing,
 * without ever touching an assignment that exists.
 *
 * Two existing engines, never a third one:
 *
 * - a line never generated gets its FIRST generation, alone
 *   (PlanningGenerationLauncher with `$onlyLines`): every other line keeps its
 *   calendar and constrains it, wherever it stands in the resolution order;
 * - a line already generated is COMPLETED (PlanningCompletionService with
 *   `$onlyLines`, D145): its holes only, every assignment it holds a fixed
 *   assignment of the solve, written into its current generation — never a
 *   new generation that would hide the assignments it holds (D125).
 *
 * One line after another, in resolution order (PlanningLineOrder, D161), each
 * written atomically on its own: a line that fails writes nothing and the
 * lines after it still run, seeing the calendar as it really is (what the
 * lines before them just wrote included). A published (or validated) line is
 * never completed, even when asked — the coherence of new assignments with the
 * publication, its stored PDF and the notifications comes first — and never
 * prevents another line from being generated. An archived line is never
 * touched. A member who joined after a line's generation is taken into
 * account by its completion (PlanningCompletionService, late snapshot
 * members).
 *
 * The whole run holds the planning's engine lock (the one the launcher and
 * the completion take, re-entrant for this session): never two runs, nor a
 * run and a generation or a completion, at once. The visible calendar is
 * measured before and after (CurrentCalendarLedger): every result says how
 * many existing assignments were modified — 0, measured, never assumed.
 */
final class PlanningLineFillService
{
    /** The engine lock of PlanningGenerationLauncher and PlanningCompletionService (same namespace on purpose). */
    private const ENGINE_LOCK_NAMESPACE = 7351;

    private const LOCKED_STATUSES = [PlanningPeriodStatus::VALIDATED, PlanningPeriodStatus::PUBLISHED, PlanningPeriodStatus::ARCHIVED];

    public function __construct(
        private readonly PlanningLineOrder $lineOrder,
        private readonly PlanningGenerationRepository $generationRepository,
        private readonly SolverParameterSetRepository $parameterSetRepository,
        private readonly PlanningResultService $resultService,
        private readonly PlanningGenerationLauncher $launcher,
        private readonly PlanningCompletionService $completionService,
        private readonly InheritedReinforcementService $inheritedReinforcements,
        private readonly LaunchResultPresenter $launchResultPresenter,
        private readonly CurrentCalendarLedger $ledger,
        private readonly EntityManagerInterface $entityManager,
        private readonly WorkHeartbeat $heartbeat,
        private readonly VacancyAnalyzer $vacancyAnalyzer,
    ) {
    }

    /**
     * What a run would do with each active line of $cycle, in resolution
     * order. Like the generation preflight (docs/decisions.md D136), it
     * materializes the weekly structure of the lines never generated — and
     * only theirs: a generated line is only read.
     */
    public function preview(Planning $planning, PlanningCycle $cycle): FillPreview
    {
        $globalBlockers = null === $this->parameterSetRepository->findLatest() ? ['NO_SOLVER_PARAMETER_SET'] : [];
        $counts = [];
        foreach ($this->resultService->forPlanning($planning, cycle: $cycle)->lines as $resultLine) {
            $counts[(int) $resultLine->line->getId()] = $resultLine;
        }
        $protectedByPeriod = [];
        foreach ($this->ledger->capture($planning) as $row) {
            $protectedByPeriod[$row['periodId']] = ($protectedByPeriod[$row['periodId']] ?? 0) + 1;
        }

        $plans = [];
        $firstGenerations = [];
        foreach ($this->lineOrder->activeInCycle($cycle) as $line) {
            $period = $line->periodIn($cycle) ?? throw new \LogicException('PlanningLineOrder::activeInCycle() only returns lines with a period in the cycle.');
            $status = $period->getStatus();
            $protected = $protectedByPeriod[(int) $period->getId()] ?? 0;
            $generated = null !== $this->generationRepository->findMostRecentCompletedByPlanningPeriod($period);
            $count = $counts[(int) $line->getId()] ?? null;

            if (PlanningPeriodStatus::ARCHIVED === $status) {
                $plans[] = new FillLinePlan($line, $status, $generated, FillLinePlan::EXCLUDED, 'archived', false, false, $count?->requiredDutyCount, $count->coveredRequiredDutyCount ?? 0, $count?->uncoveredRequiredDutyCount, $protected);
                continue;
            }

            if ($generated) {
                // docs/decisions.md D224: plus the reinforcement days that started triggering since the generation
                // (source reassigned since) — counted in memory, created by the completion itself.
                $uncovered = ($count->uncoveredRequiredDutyCount ?? 0)
                    + ($this->inheritedReinforcements->inherits($line) ? $this->inheritedReinforcements->plan($period)->newDutyCount() : 0);
                if (\in_array($status, self::LOCKED_STATUSES, true)) {
                    // docs/decisions.md D226: the one exception — the duties an extension of the period created. Its
                    // older holes stay holes, every assignment stays as it is, what it receives waits for a republication.
                    $added = $this->completionService->extensionHoleCount($line, $cycle);
                    if ($added > 0) {
                        // docs/decisions.md D234: the new days nobody can take are said, never offered alone.
                        $unfillable = $this->unfillableHoles($count, onlyExtension: true);
                        $offered = $added > $unfillable->unfillableDutyCount;
                        $plans[] = new FillLinePlan($line, $status, true, $offered ? FillLinePlan::COMPLETE : FillLinePlan::NOTHING, $offered ? 'extension_days' : 'no_eligible_member', $offered && [] === $globalBlockers, $offered && [] === $globalBlockers, $count?->requiredDutyCount, $count->coveredRequiredDutyCount ?? 0, $added, $protected, $offered ? $globalBlockers : [], extensionOnly: true, otherUncoveredCount: max(0, $uncovered - $added), unfillable: $unfillable);
                        continue;
                    }
                    // docs/decisions.md D222: a published (or validated) line is never completed by this operation, even
                    // when asked: the new assignments, the publication status, the stored PDF and the members'
                    // notifications must first be made coherent — a separate piece of work. It blocks no other line.
                    $plans[] = new FillLinePlan($line, $status, true, FillLinePlan::EXCLUDED, 'published', false, false, $count?->requiredDutyCount, $count->coveredRequiredDutyCount ?? 0, $uncovered, $protected);
                    continue;
                }
                if (0 === $uncovered) {
                    $plans[] = new FillLinePlan($line, $status, true, FillLinePlan::NOTHING, 'nothing_to_complete', false, false, $count?->requiredDutyCount, $count->coveredRequiredDutyCount ?? 0, 0, $protected);
                    continue;
                }
                $lateMembers = \count($this->completionService->lateMembersOf($line, $cycle));
                // docs/decisions.md D234: a completion offered only for posts nobody can take would assign nothing.
                // Those are counted apart; the line is completed as soon as one hole can be filled.
                $unfillable = $this->unfillableHoles($count, onlyExtension: false);
                $plans[] = $uncovered > $unfillable->unfillableDutyCount
                    ? new FillLinePlan($line, $status, true, FillLinePlan::COMPLETE, null, [] === $globalBlockers, [] === $globalBlockers, $count?->requiredDutyCount, $count->coveredRequiredDutyCount ?? 0, $uncovered, $protected, $globalBlockers, lateMemberCount: $lateMembers, unfillable: $unfillable)
                    : new FillLinePlan($line, $status, true, FillLinePlan::NOTHING, 'no_eligible_member', false, false, $count?->requiredDutyCount, $count->coveredRequiredDutyCount ?? 0, $uncovered, $protected, lateMemberCount: $lateMembers, unfillable: $unfillable);
                continue;
            }

            if (\in_array($status, self::LOCKED_STATUSES, true)) {
                // Locked without any generation: nothing any application path produces — never generated (I8).
                $plans[] = new FillLinePlan($line, $status, false, FillLinePlan::EXCLUDED, 'locked', false, false, null, 0, null, $protected);
                continue;
            }

            $plan = $this->firstGenerationPlan($planning, $cycle, $line, $protected, $firstGenerations);
            if (FillLinePlan::GENERATE === $plan->action) {
                $firstGenerations[(int) $line->getId()] = true;
            }
            $plans[] = $plan;
        }

        // docs/decisions.md D224, D226: the source line, earlier in resolution order, is about to receive holders.
        $writing = [];
        foreach ($plans as $index => $plan) {
            $source = $plan->writes() ? null : $this->inheritedReinforcements->sourceLineOf($plan->line);
            if (null !== $source && isset($writing[(int) $source->getId()]) && (FillLinePlan::NOTHING === $plan->action || 'published' === $plan->reason)) {
                $plans[$index] = $plan->followingSource();
            }
            if ($plan->writes()) {
                $writing[(int) $plan->line->getId()] = true;
            }
        }

        return new FillPreview($plans, $globalBlockers);
    }

    /**
     * Runs the selected lines of $cycle, one after another in resolution
     * order. Lines not selected, or with nothing to do, are reported as such
     * and never touched.
     *
     * @param list<string> $lineStableIds the lines asked for
     *
     * @return array{lines: list<FillLineResult>, modifiedExistingCount: int, keptExistingCount: int, addedCount: int}
     *
     * @throws PlanningGenerationInProgressException another run, a generation or a completion is running on this Planning
     */
    public function fill(Planning $planning, User $author, PlanningCycle $cycle, array $lineStableIds, RestPolicyOptions $restPolicy): array
    {
        if (!$this->tryLock($planning)) {
            throw new PlanningGenerationInProgressException();
        }

        try {
            $before = $this->ledger->capture($planning);
            // Read again under the lock: what is true now, never what the manager saw a minute ago.
            $preview = $this->preview($planning, $cycle);

            $results = [];
            foreach ($preview->lines as $plan) {
                $this->heartbeat->beat();
                if (!\in_array((string) $plan->line->getStableId(), $lineStableIds, true)) {
                    // A line that could not have been selected (published, archived…) says why, not "not selected".
                    $results[] = FillLinePlan::EXCLUDED === $plan->action
                        ? new FillLineResult($plan, 'excluded', $plan->reason, blockers: $plan->blockers)
                        : new FillLineResult($plan, 'not_selected');
                    continue;
                }
                $results[] = match ($plan->action) {
                    FillLinePlan::GENERATE => $this->generateLine($planning, $author, $cycle, $plan, $restPolicy),
                    FillLinePlan::COMPLETE => $this->completeLine($planning, $author, $cycle, $plan),
                    FillLinePlan::NOTHING => new FillLineResult($plan, 'nothing_to_do', $plan->reason),
                    default => new FillLineResult($plan, 'excluded', $plan->reason, blockers: $plan->blockers),
                };
            }

            // docs/decisions.md D224, D226: an inheriting line is decided by the holders of its source. The holders this run
            // just gave — on the new dates of a published source too — may call for reinforcements: same run.
            $results = $this->followInheritingLines($planning, $author, $cycle, $results);

            $after = $this->ledger->capture($planning);

            return $this->measured($planning, $cycle, $results, $before, $after);
        } finally {
            $this->unlock($planning);
        }
    }

    /**
     * @param list<FillLineResult> $results
     *
     * @return list<FillLineResult>
     */
    private function followInheritingLines(Planning $planning, User $author, PlanningCycle $cycle, array $results): array
    {
        $done = [];
        foreach ($results as $result) {
            if ('done' === $result->status) {
                $done[(int) $result->plan->line->getId()] = true;
            }
        }
        if ([] === $done) {
            return $results;
        }
        foreach ($this->preview($planning, $cycle)->lines as $plan) {
            $source = $this->inheritedReinforcements->sourceLineOf($plan->line);
            if (FillLinePlan::COMPLETE !== $plan->action || null === $source || !isset($done[(int) $source->getId()]) || isset($done[(int) $plan->line->getId()])) {
                continue;
            }
            $outcome = $this->completeLine($planning, $author, $cycle, $plan);
            foreach ($results as $index => $result) {
                if ($result->plan->line === $plan->line) {
                    $results[$index] = $outcome;
                }
            }
        }

        return $results;
    }

    /**
     * @param array<int, true> $firstGenerations lines given their first generation earlier in the same run
     */
    private function firstGenerationPlan(Planning $planning, PlanningCycle $cycle, PlanningLine $line, int $protected, array $firstGenerations): FillLinePlan
    {
        $period = $line->periodIn($cycle) ?? throw new \LogicException('An active line of the cycle has a period in it.');
        $preflight = $this->launcher->preflight($planning, $cycle, null, [$line]);
        $readiness = null;
        foreach ($preflight->lines as $candidate) {
            if ($candidate->line === $line) {
                $readiness = $candidate;
            }
        }
        if (null === $readiness || LaunchLineReadiness::EXCLUDE === $readiness->decision) {
            return new FillLinePlan($line, $period->getStatus(), false, FillLinePlan::EXCLUDED, 'nothing_demanded', false, false, 0, 0, 0, $protected);
        }

        $afterSource = false;
        $blockers = [];
        foreach ($preflight->blockers as $issue) {
            if (null !== $issue->line && $issue->line !== $line) {
                continue;
            }
            // A reinforcement line whose source is given its first generation just before it, in the same run.
            $source = $readiness->demandSourceLine;
            if (PreflightIssueCode::CONDITIONAL_SOURCE_NOT_GENERATED === $issue->code && null !== $source && isset($firstGenerations[(int) $source->getId()])) {
                $afterSource = true;
                continue;
            }
            $blockers[] = $issue->code->value;
        }
        $warnings = array_values(array_map(
            static fn (PreflightIssue $issue): string => $issue->code->value,
            array_filter($preflight->warnings, static fn (PreflightIssue $issue): bool => null === $issue->line || $issue->line === $line),
        ));

        // A reinforcement line's duties exist only once its source is assigned (D167): never a guessed number.
        // docs/decisions.md D224: an inheriting reinforcement line whose source already has a calendar knows its count
        // (projected in memory); otherwise it depends on the source generated in the same run — unknown, never guessed.
        $sourceUndecided = \in_array('SOURCE_NOT_DECIDED_YET', $readiness->applicability?->reasons ?? [], true);
        $required = null !== $readiness->demandSourceLine && ($sourceUndecided || !$this->inheritedReinforcements->inherits($line)) ? null : $readiness->dutyCount;
        if ([] !== $blockers) {
            return new FillLinePlan($line, $period->getStatus(), false, FillLinePlan::EXCLUDED, 'blocked', false, false, $required, 0, $required, $protected, $blockers, $warnings);
        }

        return new FillLinePlan($line, $period->getStatus(), false, FillLinePlan::GENERATE, null, true, true, $required, 0, $required, $protected, [], $warnings, $afterSource);
    }

    private function generateLine(Planning $planning, User $author, PlanningCycle $cycle, FillLinePlan $plan, RestPolicyOptions $restPolicy): FillLineResult
    {
        try {
            $all = $this->launcher->launch($planning, $author, $restPolicy, $cycle, null, [$plan->line]);
        } catch (PlanningNotLaunchableException $exception) {
            return new FillLineResult($plan, 'blocked', 'not_launchable', blockers: array_values(array_unique(array_map(
                static fn (PreflightIssue $issue): string => $issue->code->value,
                array_filter($exception->preflight->blockers, static fn (PreflightIssue $issue): bool => null === $issue->line || $issue->line === $plan->line),
            ))));
        }

        foreach ($all as $result) {
            if ($result->line !== $plan->line) {
                continue;
            }
            $written = null === $result->error && PlanningGenerationStatus::COMPLETED === $result->generation?->getStatus();

            return new FillLineResult($plan, $written ? 'done' : 'failed', $written ? null : ($result->error ?? 'generation_failed'), launch: $this->launchResultPresenter->lineToArray($result));
        }

        throw new \LogicException('A launch restricted to a line reports that line.');
    }

    private function completeLine(Planning $planning, User $author, PlanningCycle $cycle, FillLinePlan $plan): FillLineResult
    {
        try {
            $results = $this->completionService->complete($planning, $author, $cycle, [$plan->line]);
        } catch (PlanningCompletionStaleException) {
            return new FillLineResult($plan, 'calendar_changed', 'calendar_changed');
        }

        foreach ($results as $result) {
            if ($result->line === $plan->line) {
                return match ($result->status) {
                    'completed' => new FillLineResult($plan, 'done'),
                    'nothing_to_complete' => new FillLineResult($plan, 'nothing_to_do', 'nothing_to_complete'),
                    'published' => new FillLineResult($plan, 'excluded', 'published'),
                    default => new FillLineResult($plan, 'failed', $result->status),
                };
            }
        }

        throw new \LogicException('A completion restricted to a line reports that line.');
    }

    /**
     * @param list<FillLineResult>             $results
     * @param array<int, array<string, mixed>> $before
     * @param array<int, array<string, mixed>> $after
     *
     * @return array{lines: list<FillLineResult>, modifiedExistingCount: int, keptExistingCount: int, addedCount: int}
     */
    private function measured(Planning $planning, PlanningCycle $cycle, array $results, array $before, array $after): array
    {
        $changed = array_flip(CurrentCalendarLedger::changed($before, $after));
        $uncovered = [];
        foreach ($this->resultService->forPlanning($planning, cycle: $cycle)->lines as $resultLine) {
            $uncovered[(int) $resultLine->line->getId()] = null !== $resultLine->generation ? $resultLine->uncoveredRequiredDutyCount : null;
        }

        $measured = [];
        foreach ($results as $result) {
            $period = $result->plan->line->periodIn($cycle);
            $periodId = null !== $period ? (int) $period->getId() : null;
            $kept = $modified = $added = 0;
            foreach ($before as $id => $row) {
                if ($row['periodId'] === $periodId) {
                    isset($changed[$id]) ? ++$modified : ++$kept;
                }
            }
            foreach ($after as $id => $row) {
                if ($row['periodId'] === $periodId && !isset($before[$id])) {
                    ++$added;
                }
            }
            $measured[] = $result->withCounts($kept, $modified, $added, $uncovered[(int) $result->plan->line->getId()] ?? null);
        }

        return [
            'lines' => $measured,
            // The whole planning — every period, every line, selected or not: an operation on one line changes no other.
            'modifiedExistingCount' => \count($changed),
            'keptExistingCount' => \count($before) - \count($changed),
            'addedCount' => \count(array_diff_key($after, $before)),
        ];
    }

    /**
     * docs/decisions.md D234: the required holes of a generated line that
     * nobody can fill, read unit by unit with the check behind the candidate
     * list and the save (VacancyAnalyzer, the same as the publication
     * preflight). Read only: nothing is written.
     *
     * @param bool $onlyExtension only the duties an extension created (D226), the only holes of a published line
     */
    private function unfillableHoles(?PlanningResultLine $count, bool $onlyExtension): FillVacancies
    {
        if (null === $count || null === $count->generation) {
            return new FillVacancies();
        }

        $currentByDutyId = [];
        $byUnit = [];
        foreach ($count->duties as $resultDuty) {
            if (null !== $resultDuty->assignment) {
                $currentByDutyId[(int) $resultDuty->duty->getId()] = $resultDuty->assignment;
            }
            if (!$resultDuty->required || $resultDuty->covered || ($onlyExtension && null === $resultDuty->duty->getAddedByChange())) {
                continue;
            }
            $byUnit[(string) ($resultDuty->duty->getGroupInstance()?->getStableId() ?? $resultDuty->duty->getStableId())][] = $resultDuty->duty;
        }

        $unfillable = 0;
        $noMember = 0;
        $firstStart = null;
        $from = null;
        $to = null;
        foreach ($byUnit as $duties) {
            $vacancy = $this->vacancyAnalyzer->vacancyOf($count->generation, $duties, $currentByDutyId);
            if (!$vacancy->isUnfillable()) {
                continue;
            }
            $unfillable += \count($duties);
            if (PublicationVacancy::NO_MEMBER_ON_DATE === $vacancy->code) {
                $noMember += \count($duties);
                if (null !== $vacancy->firstMembershipStart && (null === $firstStart || $vacancy->firstMembershipStart < $firstStart)) {
                    $firstStart = $vacancy->firstMembershipStart;
                }
            }
            foreach ($duties as $duty) {
                $date = $duty->getLocalDate();
                $from = null === $from || $date < $from ? $date : $from;
                $to = null === $to || $date > $to ? $date : $to;
            }
        }

        return new FillVacancies($unfillable, $noMember, $firstStart, $from, $to);
    }

    private function tryLock(Planning $planning): bool
    {
        return (bool) $this->entityManager->getConnection()->fetchOne(
            'SELECT pg_try_advisory_lock(:namespace, :planning)',
            ['namespace' => self::ENGINE_LOCK_NAMESPACE, 'planning' => $planning->getId()],
        );
    }

    private function unlock(Planning $planning): void
    {
        $this->entityManager->getConnection()->fetchOne(
            'SELECT pg_advisory_unlock(:namespace, :planning)',
            ['namespace' => self::ENGINE_LOCK_NAMESPACE, 'planning' => $planning->getId()],
        );
    }
}
