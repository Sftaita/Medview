<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Planning;
use App\Entity\PlanningCycle;
use App\Entity\PlanningLine;
use App\Entity\PlanningPeriod;
use App\Entity\PlanningPeriodStatus;
use App\Entity\RestPolicyOptions;
use App\Entity\User;
use App\Exception\ConditionalSourceNotGeneratedException;
use App\Exception\NoActivePlanningRuleSetException;
use App\Exception\NoSolverParameterSetException;
use App\Exception\PlanningGenerationAlreadySnapshottedException;
use App\Exception\PlanningGenerationConcurrentSolveException;
use App\Exception\PlanningGenerationContextChangedException;
use App\Exception\PlanningGenerationInProgressException;
use App\Exception\PlanningNotLaunchableException;
use App\Exception\PlanningPeriodNotGeneratableException;
use App\Repository\DutyRepository;
use App\Repository\PlanningLineDemandPolicyRepository;
use App\Repository\PlanningRuleSetRepository;
use App\Repository\PlanningTeamMemberRepository;
use App\Repository\SolverParameterSetRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The planning-level "Générer le planning" (docs/decisions.md D129): a thin
 * façade over the existing per-line pipeline, adding no generation logic.
 *
 *   for each active PlanningLine:
 *     PlanningGenerationService::create → PlanningSnapshotService::createSnapshot
 *       → PlanningGenerationService::generate            (D106, unchanged)
 *
 * The snapshot is taken by createSnapshot() at the moment the pipeline runs,
 * from the data as it is *then*: an unavailability added after an earlier
 * generation never touches that generation's snapshot, and one added before
 * this run is part of it. The availability deadline plays no role at all —
 * it is a signal for people, not an input of the engine.
 *
 * Nothing organisational blocks a launch (members who have not confirmed,
 * passed deadline): the preflight reports them as warnings. Only technical
 * impossibilities do, and they are checked for *every* line before anything
 * is created, so a refusal never leaves an orphan DRAFT generation behind.
 *
 * Since docs/decisions.md D136, `preflight()` also materializes each active
 * line's Duty calendar on demand (`WeeklyDutyCalendarService::ensureMaterialized()`)
 * *before* counting duties — the only production entry point that ever
 * turns a configured weekly structure into real `Duty` rows. This makes
 * `preflight()` a read with a real, deliberate, idempotent side effect
 * (never a duplicate write on a repeated call) rather than a pure query —
 * an accepted trade-off (docs/decisions.md D136) so the preflight a manager
 * sees, and the launch they then click, are never out of sync: refusing to
 * materialize here would mean `preflight()` reports `NO_DUTIES` forever
 * even after a real structure is configured, until the manager clicks
 * "Générer" anyway. A line with no weekly structure configured at all
 * still legitimately blocks on `NO_DUTIES` — this never fabricates a
 * default structure.
 *
 * Since docs/decisions.md D137, `launch()` accepts an optional
 * `RestPolicyOptions`, applied identically to every active line of the
 * Planning (the same contract `PlanningGenerationController`'s per-period
 * endpoint already exposed — `RestPolicyRequestParser` is shared between
 * both, never two copies of the same parsing/validation rule). Omitted,
 * it defaults to `RestPolicyOptions::none()`, unchanged from before D137.
 */
final class PlanningGenerationLauncher
{
    /**
     * docs/decisions.md D195 (lot L4b): generate the lines of the period that
     * are not locked, keep the locked ones (validated, published, archived)
     * as they are. Required, explicitly, as soon as the period holds both.
     */
    public const SCOPE_UNLOCKED_LINES = 'unlocked_lines';

    /** Namespace of the advisory lock keyed by planning id (any constant unique to this use). */
    private const ADVISORY_LOCK_NAMESPACE = 7351;

    private const LOCKED_STATUSES = [PlanningPeriodStatus::VALIDATED, PlanningPeriodStatus::PUBLISHED, PlanningPeriodStatus::ARCHIVED];

    public function __construct(
        private readonly PlanningCollectionStatusService $statusService,
        private readonly PlanningTeamMemberRepository $teamMemberRepository,
        private readonly DutyRepository $dutyRepository,
        private readonly PlanningRuleSetRepository $ruleSetRepository,
        private readonly SolverParameterSetRepository $parameterSetRepository,
        private readonly PlanningGenerationService $generationService,
        private readonly PlanningSnapshotService $snapshotService,
        private readonly WeeklyDutyCalendarService $weeklyDutyCalendarService,
        private readonly DutyUnitFactory $dutyUnitFactory,
        private readonly EntityManagerInterface $entityManager,
        private readonly WorkHeartbeat $heartbeat,
        private readonly PlanningLineOrder $lineOrder,
        private readonly PlanningLineDemandPolicyRepository $policyRepository,
        private readonly PeriodBoundaryGuard $boundaryGuard,
        private readonly LineApplicabilityService $applicability,
    ) {
    }

    /**
     * $cycle: the period generated (docs/planning-cycles.md §5.4, lot L3) —
     * only the active lines that have a period in it are read. Omitted: the
     * planning's only period (refused when it has several,
     * MultiplePeriodsNotSupportedException).
     *
     * Each line gets a decision (docs/decisions.md D195, lot L4b), from the
     * one applicability rule (LineApplicabilityService, D194) read once its
     * weekly structure is materialized:
     *
     * - nothing demanded of it in the period → EXCLUDE: never generated,
     *   never a blocker, left as it is (a DRAFT line stays DRAFT, it is never
     *   deactivated and gets no generation);
     * - its period is locked → KEEP with $scope `unlocked_lines`; without it,
     *   PERIOD_LOCKED as before — and `requiredScope` says the scope would
     *   generate the others;
     * - otherwise → GENERATE, with every blocker of its own (a real demand
     *   with an incomplete configuration keeps blocking, explicitly), and
     *   SOURCE_OF_PUBLISHED_LINE when a reinforcement line it decides is
     *   locked.
     *
     * No line to generate at all → NO_LINE_TO_GENERATE: no job is queued.
     */
    public function preflight(Planning $planning, ?PlanningCycle $cycle = null, ?string $scope = null): PlanningGenerationPreflight
    {
        $cycle ??= $planning->getOnlyCycle();
        if (null !== $scope && self::SCOPE_UNLOCKED_LINES !== $scope) {
            throw new \InvalidArgumentException(\sprintf('Unknown generation scope "%s".', $scope));
        }
        $collection = $this->statusService->status($planning, $cycle);

        $lines = [];
        $blockers = [];
        $warnings = [];

        if (null === $this->parameterSetRepository->findLatest()) {
            $blockers[] = new PreflightIssue(PreflightIssueCode::NO_SOLVER_PARAMETER_SET);
        }

        // The resolution order (docs/decisions.md D161): launch() runs the lines in exactly this order,
        // and each line's snapshot freezes the assignments of the lines before it as fixed commitments.
        $historicalCuts = $this->boundaryGuard->historicalCutsAt($cycle);
        $activeLines = $this->lineOrder->activeInCycle($cycle);
        $locked = [];
        foreach ($activeLines as $line) {
            $period = $line->periodIn($cycle) ?? throw new \LogicException('PlanningLineOrder::activeInCycle() only returns lines with a period in the cycle.');
            $source = $this->conditionalSourceOf($line);
            $sourceMissing = null !== $source && null === $source->periodIn($cycle);
            // Sources first (PlanningLineOrder): a conditional line's source calendar already exists here (D163).
            // A conditional line whose source takes no part in this period has nothing to materialize (§5.6).
            // A locked period is never materialized (WeeklyDutyCalendarService).
            $anomalies = $sourceMissing ? [] : $this->weeklyDutyCalendarService->ensureMaterialized($period, $period->getEndsAt());

            // docs/decisions.md D194/D195: read once its duties exist — a weekly structure, a ponctual duty, a
            // remainder block (D192) or a conditional duty all make it count.
            $applicability = $this->applicability->forLine($line, $cycle);
            $isLocked = \in_array($period->getStatus(), self::LOCKED_STATUSES, true);
            $decision = match (true) {
                !$applicability->counts() => LaunchLineReadiness::EXCLUDE,
                $isLocked => LaunchLineReadiness::KEEP,
                default => LaunchLineReadiness::GENERATE,
            };
            if (LaunchLineReadiness::KEEP === $decision) {
                $locked[(int) $line->getId()] = true;
            }

            $readiness = new LaunchLineReadiness(
                $line,
                \count($this->teamMemberRepository->findIntersecting($line->getPlanningTeam(), $period->getStartsAt(), $period->getEndsAt())),
                \count($this->dutyRepository->findByPlanningPeriod($period)),
                $period->getStatus(),
                null !== $this->ruleSetRepository->findActive($line->getPlanningTeam()),
                $this->familyUnitCounts($period),
                $source,
                $decision,
                $applicability,
            );
            $lines[] = $readiness;

            if (LaunchLineReadiness::EXCLUDE === $decision) {
                // Neutral (D194): nothing to check — and nothing it could block.
                continue;
            }
            if (LaunchLineReadiness::KEEP === $decision) {
                // docs/planning-cycles.md I8 (lot L3): a validated, published or archived period is never generated
                // again — PlanningGenerationService::create() refuses it too. With the scope, it is simply kept.
                if (null === $scope) {
                    $blockers[] = new PreflightIssue(PreflightIssueCode::PERIOD_LOCKED, $line);
                }
                continue;
            }

            // docs/decisions.md D195: the prerequisites come from the one applicability rule (D194) — for a line
            // generated before too, whose own applicability only says GENERATED: they must still hold to generate
            // it again. A missing one is a blocker, never a warning: no calendar is produced that cannot hold.
            $missing = $this->applicability->missingPrerequisites($line, $cycle);
            if (\in_array('NO_ACTIVE_RULE_SET', $missing, true)) {
                $blockers[] = new PreflightIssue(PreflightIssueCode::NO_ACTIVE_RULE_SET, $line);
            }
            if (0 === $readiness->dutyCount) {
                $blockers[] = new PreflightIssue(PreflightIssueCode::NO_DUTIES, $line);
            }
            if (\in_array('CONDITIONAL_SOURCE_NOT_IN_PERIOD', $missing, true)) {
                $blockers[] = new PreflightIssue(PreflightIssueCode::CONDITIONAL_SOURCE_NOT_IN_PERIOD, $line);
            }
            // Duties demanded and nobody to hold them (case B of D195): INCOMPLETE, refused with its own code.
            if (\in_array('NO_MEMBER_IN_PERIOD', $missing, true)) {
                $blockers[] = new PreflightIssue(PreflightIssueCode::NO_MEMBER_IN_PERIOD, $line);
            }
            // A boundary with a neighbouring period that cuts one of its blocks (made before this rule existed):
            // that occurrence would be materialized in neither period, its days silently left without duty.
            if ([] !== $this->boundaryGuard->cutsForLineInCycle($line, $cycle)) {
                $blockers[] = new PreflightIssue(PreflightIssueCode::BLOCK_CUT_BY_PERIOD_BOUNDARY, $line);
            }
            // docs/decisions.md D192: a historical boundary at its start — the occurrence's days of this period are a
            // remainder block (materialized above); its earlier days, in the previous period, stay as they were.
            if ([] !== array_filter($historicalCuts, static fn (BlockCut $cut): bool => $cut->line === $line)) {
                $warnings[] = new PreflightIssue(PreflightIssueCode::HISTORICAL_BLOCK_REMAINDER, $line);
            }
            if ([] !== array_filter($anomalies, static fn (CoverageSourceAnomaly $a): bool => CoverageSourceAnomaly::AMBIGUOUS === $a->kind)) {
                $blockers[] = new PreflightIssue(PreflightIssueCode::AMBIGUOUS_COVERAGE_SOURCE, $line);
            }
            if ([] !== array_filter($anomalies, static fn (CoverageSourceAnomaly $a): bool => CoverageSourceAnomaly::MISSING === $a->kind)) {
                $warnings[] = new PreflightIssue(PreflightIssueCode::COVERAGE_SOURCE_MISSING, $line);
            }
        }

        // docs/decisions.md D195: a line deciding the demand of a locked reinforcement line is never regenerated —
        // its new result would change a demand already published. Checked once every line's status is known.
        foreach ($lines as $readiness) {
            if (!$readiness->isGenerated()) {
                continue;
            }
            foreach ($activeLines as $dependent) {
                if (isset($locked[(int) $dependent->getId()]) && $this->conditionalSourceOf($dependent) === $readiness->line) {
                    $blockers[] = new PreflightIssue(PreflightIssueCode::SOURCE_OF_PUBLISHED_LINE, $readiness->line);
                    break;
                }
            }
        }

        $generated = array_filter($lines, static fn (LaunchLineReadiness $line): bool => $line->isGenerated());
        $kept = array_filter($lines, static fn (LaunchLineReadiness $line): bool => LaunchLineReadiness::KEEP === $line->decision);
        // Nothing to generate: never a job for nothing. Without the scope, a locked line already says why (PERIOD_LOCKED).
        if ([] === $generated && (null !== $scope || [] === $kept)) {
            $blockers[] = new PreflightIssue(PreflightIssueCode::NO_LINE_TO_GENERATE);
        }
        $requiredScope = null === $scope && [] !== $kept && [] !== $generated ? self::SCOPE_UNLOCKED_LINES : null;

        if ($collection->pendingCount() > 0) {
            $warnings[] = new PreflightIssue(PreflightIssueCode::PENDING_MEMBERS);
        }
        if (null !== $collection->deadlineOverdueDays) {
            $warnings[] = new PreflightIssue(PreflightIssueCode::DEADLINE_PASSED);
        }

        return new PlanningGenerationPreflight($collection, $lines, $blockers, $warnings, $scope, $requiredScope);
    }

    /**
     * Generates $cycle — the active lines that have a period in it, nothing
     * else (docs/planning-cycles.md §5.4, lot L3). Every other period of the
     * planning is only read: its current calendar constrains the people
     * (NeighbourCommitmentCollector), it is never written.
     *
     * Only the lines the preflight decides to GENERATE are run (docs/decisions.md
     * D195): a kept (locked) line and an excluded one (nothing demanded) are
     * never touched. A kept line's current calendar is a commitment of every
     * line generated, whatever its place in the resolution order
     * (NeighbourCommitmentCollector), and a kept source decides the demand of
     * a generated reinforcement line from its current calendar.
     *
     * @return list<LaunchLineResult> one per active line of $cycle, in resolution order: the lines generated with their
     *                                result, the kept and excluded ones with only their decision (isGenerated() false)
     *
     * @throws PlanningNotLaunchableException        a blocker of the preflight applies
     * @throws PlanningGenerationInProgressException another launch of this planning is running
     */
    public function launch(Planning $planning, User $launchedBy, ?RestPolicyOptions $restPolicy = null, ?PlanningCycle $cycle = null, ?string $scope = null): array
    {
        $cycle ??= $planning->getOnlyCycle();
        if (!$this->tryLock($planning)) {
            throw new PlanningGenerationInProgressException();
        }

        try {
            $preflight = $this->preflight($planning, $cycle, $scope);
            if (!$preflight->canGenerate()) {
                throw new PlanningNotLaunchableException($preflight);
            }
            $generatedLineIds = [];
            foreach ($preflight->generatedLines() as $readiness) {
                $generatedLineIds[(int) $readiness->line->getId()] = true;
            }

            // 1. Solve, one line after another, in resolution order (PlanningLineOrder) — writing nothing
            // (docs/decisions.md D191). A line's snapshot is taken once the lines before it are solved, and sees
            // what they are about to hold (PlannedCalendar) as commitments: the D161 priority is unchanged. A line
            // that fails keeps its calendar, which the lines after it see as it is.
            // A conditional line (docs/decisions.md D164) additionally needs its source line to have produced a
            // usable result in THIS launch — its demand is decided from it. Otherwise it is not resolved at all: no
            // generation is created for it (never an older source result, never a fake one).
            $results = [];
            $solvedLineIds = [];
            $planned = new PlannedCalendar();
            $solved = [];
            foreach ($preflight->lines as $readiness) {
                $line = $readiness->line;
                if (!$readiness->isGenerated()) {
                    // Kept (locked) or excluded (nothing demanded): never touched — no generation, no status change.
                    $results[] = new LaunchLineResult($line, null, null, null, decision: $readiness->decision, applicability: $readiness->applicability);
                    continue;
                }
                $source = $this->conditionalSourceOf($line);
                // A source generated in this launch must have produced a usable result. A source this launch does not
                // generate (kept: its period is locked) is read as it is now — its current calendar (D195).
                if (null !== $source && isset($generatedLineIds[(int) $source->getId()]) && !isset($solvedLineIds[(int) $source->getId()])) {
                    $results[] = new LaunchLineResult($line, null, null, null, 'source_generation_failed');
                    continue;
                }

                [$result, $solvedGeneration] = $this->runLine($line, $cycle, $launchedBy, $restPolicy ?? RestPolicyOptions::none(), $planned);
                if (null !== $solvedGeneration && $solvedGeneration->usable) {
                    $planned->add($solvedGeneration->generation, $this->generationService->plannedCommitments($solvedGeneration));
                    $solved[] = $solvedGeneration;
                    $solvedLineIds[(int) $line->getId()] = true;
                }
                $results[] = $result;
            }

            // 2. Write once, checked under the calendar lock: what stays coherent with the calendars that remain.
            $this->heartbeat->beat();
            $notWritten = [] !== $solved ? $this->generationService->writeLaunch($planning, $solved) : [];

            return array_map(static function (LaunchLineResult $result) use ($notWritten): LaunchLineResult {
                $reason = null !== $result->generation ? ($notWritten[(int) $result->generation->getId()] ?? null) : null;
                if (null === $reason) {
                    return $result;
                }

                return new LaunchLineResult(
                    $result->line,
                    $result->generation,
                    $result->snapshot,
                    null,
                    $reason instanceof PlanningGenerationContextChangedException ? $reason->reason : 'stale_generation_data',
                    $result->demand,
                    $reason instanceof PlanningGenerationContextChangedException ? $reason->conflicts : [],
                );
            }, $results);
        } finally {
            $this->unlock($planning);
        }
    }

    /**
     * REQUIRED DutyUnit count per AllocationFamily name (docs/decisions.md
     * D137) — the "Structure" section of the preflight (§10 of the spec):
     * counted once per unit via `DutyUnitFactory`, never once per
     * constituent Duty (D136 Scenario F). A unit whose pattern carries no
     * family is counted under the empty-string key. Names, never
     * hardcoded labels — this is exactly why the dimension is generic
     * (D136): two teams can show completely different family names here.
     *
     * @return array<string, int>
     */
    private function familyUnitCounts(PlanningPeriod $period): array
    {
        $units = $this->dutyUnitFactory->fromDuties($this->dutyRepository->findByPlanningPeriod($period));

        $counts = [];
        foreach ($units as $unit) {
            // A conditional unit has no intrinsic demand (D163): it is never counted as REQUIRED structure here.
            if ($unit->getDuties()[0]->isConditional() || !$unit->isRequired()) {
                continue;
            }
            $familyName = $unit->getDuties()[0]->getAllocationFamily()?->getName() ?? '';
            $counts[$familyName] = ($counts[$familyName] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * @return array{0: LaunchLineResult, 1: ?SolvedGeneration} the line's result, and its solved generation (written later)
     */
    private function runLine(PlanningLine $line, PlanningCycle $cycle, User $launchedBy, RestPolicyOptions $restPolicy, PlannedCalendar $planned): array
    {
        // The same three steps the per-period endpoints expose, unchanged.
        // Since docs/decisions.md D137, the caller chooses the rest policy
        // for this launch (same contract as the per-period endpoint,
        // App\Service\RestPolicyRequestParser) — every active line of the
        // Planning gets the exact same choice, never one different per
        // line; a manager who genuinely needs different rest rules per
        // line already has the per-period endpoint for that.
        $this->heartbeat->beat();
        $period = $line->periodIn($cycle) ?? throw new \LogicException('A launched line always has a period in the launched cycle.');
        try {
            $generation = $this->generationService->create($period, $launchedBy, $restPolicy);
        } catch (PlanningPeriodNotGeneratableException) {
            // Locked between the preflight and now (a publication of this period): never generated (I8).
            return [new LaunchLineResult($line, null, null, null, 'period_locked'), null];
        }

        try {
            $snapshot = $this->snapshotService->createSnapshot($generation, $planned);
        } catch (NoActivePlanningRuleSetException|PlanningGenerationAlreadySnapshottedException) {
            return [new LaunchLineResult($line, $generation, null, null, 'snapshot_failed'), null];
        } catch (ConditionalSourceNotGeneratedException) {
            return [new LaunchLineResult($line, $generation, null, null, 'source_generation_failed'), null];
        }
        $demand = LineDemandSummary::ofSnapshot($snapshot);

        try {
            $solved = $this->generationService->solve($generation);
        } catch (NoSolverParameterSetException) {
            return [new LaunchLineResult($line, $generation, $snapshot, null, 'no_solver_parameter_set', $demand), null];
        } catch (PlanningGenerationConcurrentSolveException) {
            return [new LaunchLineResult($line, $generation, $snapshot, null, 'generation_not_solvable', $demand), null];
        }

        // An unusable outcome is already recorded FAILED (solve()); a usable one is written at the end of the launch.
        return [new LaunchLineResult($line, $generation, $snapshot, $solved->result, null, $demand), $solved];
    }

    /** The source line of $line when its demand policy in force is conditional (D162), null otherwise. */
    private function conditionalSourceOf(PlanningLine $line): ?PlanningLine
    {
        $policy = $this->policyRepository->findActiveForLine($line);

        return null !== $policy && $policy->getMode()->isConditional() ? $policy->getSourceLine() : null;
    }

    /**
     * A session-level Postgres advisory lock per planning: a second click (or
     * a second admin) gets an immediate refusal instead of a second, parallel
     * run over the same data. Deliberately not a row lock inside a
     * transaction: PlanningGenerationService::generate() claims and records
     * its own state in separate transactions on purpose (D106) and must not
     * be wrapped in one.
     */
    private function tryLock(Planning $planning): bool
    {
        return (bool) $this->entityManager->getConnection()->fetchOne(
            'SELECT pg_try_advisory_lock(:namespace, :planning)',
            ['namespace' => self::ADVISORY_LOCK_NAMESPACE, 'planning' => $planning->getId()],
        );
    }

    private function unlock(Planning $planning): void
    {
        $this->entityManager->getConnection()->fetchOne(
            'SELECT pg_advisory_unlock(:namespace, :planning)',
            ['namespace' => self::ADVISORY_LOCK_NAMESPACE, 'planning' => $planning->getId()],
        );
    }
}
