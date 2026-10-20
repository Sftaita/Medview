<?php

declare(strict_types=1);

namespace App\Service;

use App\Eligibility\DutyUnit;
use App\Entity\Planning;
use App\Entity\PlanningGeneration;
use App\Entity\PlanningGenerationStatus;
use App\Entity\PlanningPeriod;
use App\Entity\PlanningPeriodStatus;
use App\Entity\PlanningSnapshot;
use App\Entity\PlanningSnapshotExternalCommitment;
use App\Entity\PlanningTeamMember;
use App\Entity\RestPolicyOptions;
use App\Entity\SolverParameterSet;
use App\Entity\SolverRunMetadata;
use App\Entity\User;
use App\Exception\NoSolverParameterSetException;
use App\Exception\PlanningGenerationConcurrentSolveException;
use App\Exception\PlanningGenerationContextChangedException;
use App\Exception\PlanningPeriodNotGeneratableException;
use App\Exception\StalePlanningGenerationDataException;
use App\Fairness\CoverageStatus;
use App\Fairness\OptimizationProblem;
use App\Fairness\OptimizationResult;
use App\Fairness\PlanningSolver;
use App\Fairness\SolverStatus;
use App\Repository\PlanningSnapshotRepository;
use App\Repository\PlanningSnapshotRuleSetRepository;
use App\Repository\PlanningTeamMemberRepository;
use App\Repository\SolverParameterSetRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\OptimisticLockException;

/**
 * Orchestrates a full, real generation attempt (docs/decisions.md D106):
 *
 *   PlanningGeneration (SNAPSHOTTED) → OptimizationProblem → PlanningSolver
 *     → OptimizationResult → DutyAssignment AUTO (persisted atomically)
 *
 * `create()` (Lot 3) is unchanged. `generate()` (Lot 6E) is the new
 * orchestration — controllers never perform any of these steps themselves
 * (CLAUDE.md: "aucune logique métier dans les contrôleurs").
 */
final class PlanningGenerationService
{
    private const LOCKED_STATUSES = [PlanningPeriodStatus::VALIDATED, PlanningPeriodStatus::PUBLISHED, PlanningPeriodStatus::ARCHIVED];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PlanningSnapshotRepository $snapshotRepository,
        private readonly PlanningSnapshotRuleSetRepository $snapshotRuleSetRepository,
        private readonly PlanningTeamMemberRepository $teamMemberRepository,
        private readonly SolverParameterSetRepository $parameterSetRepository,
        private readonly EligibilityMatrixBuilder $matrixBuilder,
        private readonly FairnessContextBuilder $contextBuilder,
        private readonly OptimizationProblemBuilder $problemBuilder,
        private readonly PlanningSolver $solver,
        private readonly SnapshotHasher $snapshotHasher,
        private readonly SeedMaterialBuilder $seedMaterialBuilder,
        private readonly DutyAssignmentService $assignmentService,
        private readonly UnsatReportPresenter $unsatReportPresenter,
        private readonly CalendarWriteLock $calendarWriteLock,
        private readonly NeighbourCommitmentCollector $neighbourCommitments,
        private readonly PersonCommitmentReader $commitmentReader,
        private readonly PersonCommitmentChecker $commitmentChecker,
    ) {
    }

    /**
     * @throws PlanningPeriodNotGeneratableException the period is VALIDATED, PUBLISHED or ARCHIVED (I8, lot L3)
     */
    public function create(PlanningPeriod $planningPeriod, ?User $createdBy, ?RestPolicyOptions $restPolicy = null): PlanningGeneration
    {
        // docs/planning-cycles.md I8: a validated, published or archived period is never generated again.
        if (\in_array($planningPeriod->getStatus(), self::LOCKED_STATUSES, true)) {
            throw new PlanningPeriodNotGeneratableException();
        }
        $generation = new PlanningGeneration($planningPeriod, $createdBy, $restPolicy ?? RestPolicyOptions::none());
        $this->entityManager->persist($generation);
        $this->entityManager->flush();

        return $generation;
    }

    /**
     * @throws PlanningGenerationConcurrentSolveException if $generation is
     *                                                    not SNAPSHOTTED,
     *                                                    or another solve
     *                                                    claimed it first
     *                                                    (real DB-level
     *                                                    optimistic-lock
     *                                                    race, never just
     *                                                    an in-memory
     *                                                    check)
     * @throws NoSolverParameterSetException              if the system has
     *                                                    never been seeded
     *                                                    with a
     *                                                    SolverParameterSet
     * @throws PlanningGenerationContextChangedException  the neighbouring
     *                                                    calendar changed, or
     *                                                    the period was
     *                                                    locked, during the
     *                                                    solve (lot L3) — the
     *                                                    generation is left
     *                                                    FAILED, nothing else
     *                                                    is persisted
     * @throws StalePlanningGenerationDataException       if a new Duty was
     *                                                    added to this
     *                                                    PlanningPeriod
     *                                                    while the solve
     *                                                    subprocess was
     *                                                    running — the
     *                                                    generation is left
     *                                                    FAILED, nothing is
     *                                                    ever persisted
     */
    public function generate(PlanningGeneration $generation): OptimizationResult
    {
        // The technical per-period path (docs/planning-generation.md §13): a launch of one generation — solved, then
        // written by the same checked, locked write as a planning-level launch (docs/decisions.md D191).
        $solved = $this->solve($generation);
        if (!$solved->usable) {
            return $solved->result;
        }

        $outcome = $this->writeLaunch($generation->getPlanningPeriod()->getTeam()->getPlanning(), [$solved])[(int) $generation->getId()] ?? null;
        if (null !== $outcome) {
            throw $outcome;
        }

        return $solved->result;
    }

    /**
     * Claims and solves $generation, writing nothing to the calendar
     * (docs/decisions.md D191): its result is written later, by
     * writeLaunch(), once every line of the launch is solved. An unusable
     * outcome is recorded FAILED here, with its metadata, and never written.
     *
     * @throws PlanningGenerationConcurrentSolveException
     * @throws NoSolverParameterSetException
     */
    public function solve(PlanningGeneration $generation): SolvedGeneration
    {
        $this->claimSolving($generation);

        $snapshot = $this->snapshotRepository->findOneByGeneration($generation);
        if (null === $snapshot) {
            // Cannot happen through the real application flow (claimSolving()
            // already required status === SNAPSHOTTED, and a generation only
            // reaches that status once PlanningSnapshotService has created
            // exactly one PlanningSnapshot for it) — defense in depth, not a
            // path any test drives through the public API.
            throw new \LogicException('A SNAPSHOTTED PlanningGeneration has no PlanningSnapshot — data integrity bug.');
        }

        $parameterSet = $this->parameterSetRepository->findLatest();
        if (null === $parameterSet) {
            throw new NoSolverParameterSetException();
        }

        $rulesVersion = $this->snapshotRuleSetRepository->findOneBySnapshot($snapshot)?->getSourceVersion() ?? 0;
        $snapshotHashBefore = $this->snapshotHasher->hash($snapshot);
        $seed = $this->seedMaterialBuilder->build($snapshot, $rulesVersion, $snapshotHashBefore, OptimizationProblemBuilder::ALGORITHM_VERSION, $parameterSet->getVersion());

        $matrix = $this->matrixBuilder->build($snapshot);
        $context = $this->contextBuilder->build($snapshot, $matrix);
        $problem = $this->problemBuilder->build($context, $parameterSet);

        $result = $this->solver->solve($problem);

        $metadata = new SolverRunMetadata(
            OptimizationProblemBuilder::ALGORITHM_VERSION,
            $result->solverMetadata?->solverType ?? 'unknown',
            $result->solverMetadata?->solverVersion ?? '',
            $parameterSet->getVersion(),
            $seed,
            $snapshotHashBefore,
            $result->strictSolverStatus,
            $result->partialSolverStatus,
            $result->coverageStatus,
            $result->objectiveValues,
            $result->optimality,
            $result->solverMetadata?->solveDurationMs ?? 0,
            $result->solverMetadata?->timeoutHit ?? false,
            $this->describeOutcome($result),
            // Persisted exactly as computed here, never recomputed later (docs/decisions.md D130) —
            // same condition and same shape `POST .../solve` already returned transiently.
            CoverageStatus::INCOMPLETE === $result->coverageStatus
                ? $this->unsatReportPresenter->toArray($result->diagnostics)
                : null,
        );

        $usable = $this->isUsableOutcome($result);
        if (!$usable) {
            $this->persistFailedOutcome($generation, $metadata, $parameterSet);
        }

        return new SolvedGeneration($generation, $snapshot, $problem, $result, $metadata, $parameterSet, $snapshotHashBefore, $usable);
    }

    /**
     * What a solved generation would write, as commitments of the people it
     * assigns — what the lines solved after it in the same launch read
     * (PlannedCalendar, docs/decisions.md D191).
     *
     * @return list<PersonCommitment>
     */
    public function plannedCommitments(SolvedGeneration $solved): array
    {
        $line = $solved->generation->getPlanningPeriod()->getLine();
        if (null === $line || !$solved->usable) {
            return [];
        }

        $commitments = [];
        foreach ($this->assignedUnits($solved) as [$unit, $teamMember]) {
            foreach ($unit->getDuties() as $duty) {
                $commitments[] = new PersonCommitment($line, $solved->generation, $duty, $teamMember);
            }
        }

        return $commitments;
    }

    /**
     * Writes the solved generations of a launch (docs/decisions.md D191), in
     * resolution order, in ONE transaction under the Planning's
     * CalendarWriteLock — the lock every write to its current calendar takes
     * (reassignment, swap, completion, publication, D144) — held from the
     * checks to the commit.
     *
     * Each generation must still be valid on its own (never written because
     * the final calendar merely looks coherent):
     *
     * - its period is not validated/published/archived (I8);
     * - its Duty list is unchanged (D106, snapshotHash);
     * - what its people hold elsewhere is exactly what its snapshot froze —
     *   read again with the generations accepted before it as they are about
     *   to be (NeighbourCommitmentCollector, PlannedCalendar);
     * - a conditional line's source result of the launch is written too.
     *
     * Then the final calendar — the accepted results, the calendars that stay
     * (a line of the launch that failed keeps its previous one), every other
     * period — is checked person by person: overlap, rest (the stricter of
     * both generations' thresholds) and self-coverage
     * (PersonCommitmentChecker, the rule of every other check). A generation
     * of the launch involved in a violation is not written, and everything
     * after it is checked again without it; what remains coherent is
     * written. Nothing existing is ever changed by a generation that is not
     * written.
     *
     * @param list<SolvedGeneration> $solved in resolution order, usable ones only
     *
     * @return array<int, StalePlanningGenerationDataException|PlanningGenerationContextChangedException> why each generation
     *                                                                                                    NOT written was not, by generation id
     */
    public function writeLaunch(Planning $planning, array $solved): array
    {
        /** @var array<int, StalePlanningGenerationDataException|PlanningGenerationContextChangedException> $discarded */
        $discarded = [];
        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();
        try {
            $this->calendarWriteLock->acquire($planning);

            do {
                [$accepted, $planned, $refused] = $this->acceptable($solved, $discarded);
                $discarded += $refused;
                $conflicts = $this->finalCalendarConflicts($planning, $accepted, $planned);
                foreach ($conflicts as $generationId => $list) {
                    $discarded[$generationId] = new PlanningGenerationContextChangedException(
                        PlanningGenerationContextChangedException::LAUNCH_INCONSISTENT,
                        $this->describeConflicts($list),
                        $list,
                    );
                }
            } while ([] !== $conflicts);

            foreach ($accepted as $generation) {
                $this->writeAssignments($generation);
            }
            $connection->commit();
        } catch (\Throwable $exception) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }

            throw $exception;
        }

        // Recorded after the commit, each in its own small transaction: the audit of a run whose result was not written.
        foreach ($solved as $generation) {
            $reason = $discarded[(int) $generation->generation->getId()] ?? null;
            if (null !== $reason) {
                $this->recordDiscardedOutcome($generation->generation, $generation->metadata, $generation->parameterSet, $generation->snapshotHashBefore, $reason->getMessage());
            }
        }

        return $discarded;
    }

    /**
     * The generations still writable, in order, given those already
     * discarded: each checked on its own against the calendar as the ones
     * accepted before it will leave it.
     *
     * @param list<SolvedGeneration>                                                                     $solved
     * @param array<int, StalePlanningGenerationDataException|PlanningGenerationContextChangedException> $discarded
     *
     * @return array{0: list<SolvedGeneration>, 1: PlannedCalendar, 2: array<int, StalePlanningGenerationDataException|PlanningGenerationContextChangedException>}
     */
    private function acceptable(array $solved, array $discarded): array
    {
        $accepted = [];
        $planned = new PlannedCalendar();
        $refused = [];
        $notWritten = [];
        foreach ($solved as $generation) {
            $id = (int) $generation->generation->getId();
            if (isset($discarded[$id])) {
                $notWritten[(string) $generation->generation->getStableId()] = true;
                continue;
            }

            // A conditional line's demand was decided from its source's result of this launch (D164): never without it.
            $source = $generation->snapshot->getDemandPolicy()?->getSourceGenerationStableId();
            if (null !== $source && isset($notWritten[(string) $source])) {
                $refused[$id] = new PlanningGenerationContextChangedException(
                    PlanningGenerationContextChangedException::SOURCE_NOT_WRITTEN,
                    'The source line\'s result of this launch is not written: this reinforcement line\'s demand was decided from it — nothing was persisted.',
                );
                $notWritten[(string) $generation->generation->getStableId()] = true;
                continue;
            }

            $stale = $this->staleInput($generation->generation, $generation->snapshot, $generation->snapshotHashBefore, $planned);
            if (null !== $stale) {
                $refused[$id] = $stale;
                $notWritten[(string) $generation->generation->getStableId()] = true;
                continue;
            }

            $accepted[] = $generation;
            $planned->add($generation->generation, $this->plannedCommitments($generation));
        }

        return [$accepted, $planned, $refused];
    }

    /**
     * Every violation the final calendar would hold that involves an accepted
     * result, by the accepted generation(s) involved. Checked both ways, so
     * that a self-coverage (a reinforcement held by the holder of its own
     * source duty, D163) is caught whichever side is new.
     *
     * @param list<SolvedGeneration> $accepted
     *
     * @return array<int, list<array<string, mixed>>> by generation id
     */
    private function finalCalendarConflicts(Planning $planning, array $accepted, PlannedCalendar $planned): array
    {
        $periods = $this->commitmentReader->periodsOfActiveLines($planning);
        foreach ($accepted as $generation) {
            if (!\in_array($generation->generation->getPlanningPeriod(), $periods, true)) {
                $periods[] = $generation->generation->getPlanningPeriod();
            }
        }

        $byUser = [];
        foreach ($accepted as $generation) {
            foreach ($planned->commitmentsOf($generation->generation->getPlanningPeriod()) as $commitment) {
                $byUser[(int) $commitment->teamMember->getUser()->getId()][] = $commitment;
            }
        }

        $conflicts = [];
        $seen = [];
        foreach ($byUser as $new) {
            $everything = $this->commitmentReader->forUserInPeriods($periods, $new[0]->teamMember->getUser(), $planned);
            foreach ($new as $x) {
                foreach ($everything as $y) {
                    if ($y->generation === $x->generation) {
                        continue; // one generation's own duties: the solve's own constraints
                    }
                    $pair = [(int) $x->duty->getId(), (int) $y->duty->getId()];
                    sort($pair);
                    if (isset($seen[implode('-', $pair)])) {
                        continue;
                    }

                    $violation = $this->commitmentChecker->firstViolation([$x->duty], [$y->toIntervalFor($x->generation)], $x->generation->getRestPolicy())
                        ?? $this->commitmentChecker->firstViolation([$y->duty], [$x->toIntervalFor($y->generation)], $y->generation->getRestPolicy());
                    if (null === $violation) {
                        continue;
                    }
                    $seen[implode('-', $pair)] = true;

                    $conflict = [
                        'personStableId' => (string) $x->teamMember->getUser()->getStableId(),
                        'lineName' => $x->line->getName(),
                        'dutyStableId' => (string) $x->duty->getStableId(),
                        'day' => $x->duty->getLocalDate()->format('Y-m-d'),
                        'otherLineName' => $y->line->getName(),
                        'otherDutyStableId' => (string) $y->duty->getStableId(),
                        'otherDay' => $y->duty->getLocalDate()->format('Y-m-d'),
                        'otherPeriodNumber' => $y->generation->getPlanningPeriod()->getCycle()?->getNumber(),
                        'otherWritten' => !$planned->covers($y->generation->getPlanningPeriod()),
                        'reason' => $violation->reason->value,
                    ];
                    $conflicts[(int) $x->generation->getId()][] = $conflict;
                    // Two results of the same launch in conflict (never expected: the later one froze the earlier one).
                    if ($planned->covers($y->generation->getPlanningPeriod())) {
                        $conflicts[(int) $y->generation->getId()][] = $conflict;
                    }
                }
            }
        }

        return $conflicts;
    }

    /**
     * @param list<array<string, mixed>> $conflicts
     */
    private function describeConflicts(array $conflicts): string
    {
        $shown = array_map(
            static fn (array $c): string => \sprintf('%s %s ↔ %s %s (%s)', $c['lineName'], $c['day'], $c['otherLineName'], $c['otherDay'], $c['reason']),
            \array_slice($conflicts, 0, 5),
        );

        return \sprintf(
            'Not written: with the calendars that stay as they are, the result would break %d rule(s) — %s%s. Nothing of it was persisted.',
            \count($conflicts),
            implode('; ', $shown),
            \count($conflicts) > 5 ? '; …' : '',
        );
    }

    /**
     * @return list<array{0: DutyUnit, 1: PlanningTeamMember}>
     */
    private function assignedUnits(SolvedGeneration $solved): array
    {
        $dutyUnitsByKey = [];
        foreach ([...$solved->problem->getRequiredDutyUnits(), ...$solved->problem->getOptionalDutyUnits()] as $unit) {
            $dutyUnitsByKey[$unit->getStableKey()] = $unit;
        }

        $assigned = [];
        $teamMembersByStableId = [];
        foreach ($solved->result->assignments as $edge) {
            $teamMember = $teamMembersByStableId[$edge->sourceTeamMemberStableId]
                ??= $this->teamMemberRepository->findOneByStableId($edge->sourceTeamMemberStableId);

            if (null === $teamMember) {
                // The solver only ever assigns to a candidate id that came
                // from EligibilityMatrix, which is itself built from real
                // PlanningSnapshotMember rows (docs/eligibility.md) — a
                // missing live PlanningTeamMember here would mean the
                // adapter fabricated an id, a bug, not a real runtime case.
                throw new \LogicException(sprintf('OrToolsPlanningSolver returned an assignment for an unknown TeamMember stableId "%s".', $edge->sourceTeamMemberStableId));
            }

            $unit = $dutyUnitsByKey[$edge->dutyUnitStableKey] ?? null;
            if (null === $unit) {
                throw new \LogicException(sprintf('OrToolsPlanningSolver returned an assignment for an unknown DutyUnit stableKey "%s".', $edge->dutyUnitStableKey));
            }

            $assigned[] = [$unit, $teamMember];
        }

        return $assigned;
    }

    /**
     * The real, DB-level concurrency claim (docs/decisions.md D106) —
     * relies on PlanningGeneration::$lockVersion (Doctrine optimistic
     * locking), never merely an in-memory status check. Its own tiny
     * transaction, deliberately separate from the big one at the end of
     * generate(): claiming the slot must be visible to a concurrent
     * request immediately, before the (potentially long) solve even
     * starts.
     *
     * @throws PlanningGenerationConcurrentSolveException
     */
    private function claimSolving(PlanningGeneration $generation): void
    {
        if (PlanningGenerationStatus::SNAPSHOTTED !== $generation->getStatus()) {
            throw new PlanningGenerationConcurrentSolveException();
        }

        $generation->transitionTo(PlanningGenerationStatus::SOLVING);

        try {
            $this->entityManager->flush();
        } catch (OptimisticLockException) {
            throw new PlanningGenerationConcurrentSolveException('Another solve already claimed this PlanningGeneration concurrently.');
        }
    }

    private function isUsableOutcome(OptimizationResult $result): bool
    {
        if (SolverStatus::ERROR === $result->strictSolverStatus || SolverStatus::UNKNOWN === $result->strictSolverStatus) {
            return false;
        }

        if (\in_array($result->strictSolverStatus, [SolverStatus::OPTIMAL, SolverStatus::FEASIBLE], true)) {
            return true;
        }

        // strictSolverStatus === UNSATISFIABLE: only usable if PARTIAL
        // actually concluded with a real (possibly incomplete) result.
        return \in_array($result->partialSolverStatus, [SolverStatus::OPTIMAL, SolverStatus::FEASIBLE], true);
    }

    private function describeOutcome(OptimizationResult $result): ?string
    {
        if ($this->isUsableOutcome($result)) {
            return null;
        }

        if (isset($result->solverMetadata?->parameters['error'])) {
            return (string) $result->solverMetadata->parameters['error'];
        }

        return sprintf(
            'strictSolverStatus=%s, partialSolverStatus=%s — no usable assignment set was produced.',
            $result->strictSolverStatus->value,
            $result->partialSolverStatus?->value ?? 'null',
        );
    }

    /**
     * Why the solve's result can no longer be written, or null. Called under
     * the calendar lock.
     */
    private function staleInput(PlanningGeneration $generation, PlanningSnapshot $snapshot, string $snapshotHashBefore, PlannedCalendar $planned): StalePlanningGenerationDataException|PlanningGenerationContextChangedException|null
    {
        $period = $generation->getPlanningPeriod();
        // From the database, never the EntityManager's copy: a publication runs in another process.
        $status = PlanningPeriodStatus::from((string) $this->entityManager->getConnection()->fetchOne('SELECT status FROM planning_periods WHERE id = ?', [$period->getId()]));
        if (\in_array($status, self::LOCKED_STATUSES, true)) {
            return new PlanningGenerationContextChangedException(
                PlanningGenerationContextChangedException::PERIOD_LOCKED,
                'This period was validated or published while the solve was in progress — the computed result was discarded, nothing was persisted.',
            );
        }

        // docs/allocation-algorithm.md §15 "Concurrence", docs/decisions.md
        // D106: the live Duty list (docs/planning-generation.md §4 — Duty is
        // never duplicated into the snapshot). Re-hashing right before
        // persisting and comparing against the hash computed right before
        // solving catches a Duty added while the (real, non-instant)
        // subprocess was running.
        if ($this->snapshotHasher->hash($snapshot) !== $snapshotHashBefore) {
            return new StalePlanningGenerationDataException();
        }

        $userStableIds = [];
        foreach ($snapshot->getMembers() as $member) {
            $userStableIds[(string) $member->getSourceUserStableId()] = true;
        }
        $now = array_map(
            static fn (PersonCommitment $c): string => self::commitmentKey(
                (string) $c->teamMember->getUser()->getStableId(),
                (string) $c->duty->getStableId(),
                (string) $c->generation->getStableId(),
                $c->duty->getStartsAt(),
                $c->duty->getEndsAt(),
                $c->generation->getRestPolicy()->legalMinRestEnabled ? $c->generation->getRestPolicy()->legalMinRestHours : null,
                $c->generation->getRestPolicy()->teamMinRestEnabled ? $c->generation->getRestPolicy()->teamMinRestHours : null,
            ),
            $this->neighbourCommitments->collect($period, $generation->getRestPolicy(), $userStableIds, $planned),
        );
        $frozen = array_map(
            static fn (PlanningSnapshotExternalCommitment $c): string => self::commitmentKey(
                (string) $c->getSourceUserStableId(),
                (string) $c->getSourceDutyStableId(),
                (string) $c->getSourceGenerationStableId(),
                $c->getStartsAt(),
                $c->getEndsAt(),
                $c->getSourceLegalMinRestHours(),
                $c->getSourceTeamMinRestHours(),
            ),
            $snapshot->getExternalCommitments()->toArray(),
        );
        sort($now);
        sort($frozen);
        if ($now !== $frozen) {
            return new PlanningGenerationContextChangedException(
                PlanningGenerationContextChangedException::NEIGHBOUR_CALENDAR_CHANGED,
                'What the people of this generation hold on other lines or in other periods changed while the solve was in progress — the computed result was discarded, nothing was persisted.',
            );
        }

        if ($this->sourceHoldersChanged($snapshot, $planned)) {
            return new PlanningGenerationContextChangedException(
                PlanningGenerationContextChangedException::SOURCE_CALENDAR_CHANGED,
                'A holder of the source line changed while the solve was in progress: the demand of this reinforcement line was decided from a calendar that no longer exists — the computed result was discarded, nothing was persisted.',
            );
        }

        return null;
    }

    /**
     * docs/decisions.md D195: a conditional line whose source is NOT
     * generated by this launch (kept: its period is locked) had its demand
     * decided from the source's current holders, read when the snapshot was
     * taken (PlanningSnapshotDemandDecision). Read them again, from the
     * database, under the calendar lock: any difference — whoever the people
     * are, members of this line or not — means that demand is stale. A source
     * generated by the same launch is checked by SOURCE_NOT_WRITTEN and the
     * planned calendar instead.
     */
    private function sourceHoldersChanged(PlanningSnapshot $snapshot, PlannedCalendar $planned): bool
    {
        $policy = $snapshot->getDemandPolicy();
        if (null === $policy) {
            return false;
        }

        $connection = $this->entityManager->getConnection();
        $source = $connection->fetchAssociative('SELECT id, planning_period_id FROM planning_generations WHERE stable_id = ?', [(string) $policy->getSourceGenerationStableId()]);
        if (false === $source) {
            return true;
        }
        $sourcePeriod = $this->entityManager->find(PlanningPeriod::class, (int) $source['planning_period_id']);
        if (null !== $sourcePeriod && $planned->covers($sourcePeriod)) {
            return false;
        }

        $holders = [];
        foreach ($connection->fetchAllAssociative(<<<'SQL'
            SELECT d.stable_id AS duty, u.stable_id AS holder
            FROM duty_assignments a
            JOIN duties d ON d.id = a.duty_id
            JOIN planning_team_members tm ON tm.id = a.team_member_id
            JOIN users u ON u.id = tm.user_id
            WHERE a.generation_id = ? AND a.current
            SQL, [(int) $source['id']]) as $row) {
            $holders[(string) $row['duty']] = (string) $row['holder'];
        }

        foreach ($snapshot->getDemandDecisions() as $decision) {
            $frozen = null !== $decision->getSourceUserStableId() ? (string) $decision->getSourceUserStableId() : null;
            if (($holders[(string) $decision->getSourceDutyStableId()] ?? null) !== $frozen) {
                return true;
            }
        }

        return false;
    }

    private static function commitmentKey(string $user, string $duty, string $generation, \DateTimeImmutable $startsAt, \DateTimeImmutable $endsAt, ?int $legal, ?int $team): string
    {
        return implode('|', [$user, $duty, $generation, $startsAt->getTimestamp(), $endsAt->getTimestamp(), $legal ?? '-', $team ?? '-']);
    }

    /** FAILED, the solver's own metadata kept as the audit of a run whose result was thrown away. */
    private function recordDiscardedOutcome(PlanningGeneration $generation, SolverRunMetadata $metadata, SolverParameterSet $parameterSet, string $snapshotHashBefore, string $reason): void
    {
        $staleMetadata = new SolverRunMetadata(
            $metadata->algorithmVersion,
            $metadata->solverType,
            $metadata->solverVersion,
            $metadata->solverParameterSetVersion,
            $metadata->seed,
            $snapshotHashBefore,
            $metadata->strictSolverStatus,
            $metadata->partialSolverStatus,
            $metadata->coverageStatus,
            $metadata->objectiveValues,
            $metadata->optimality,
            $metadata->solveDurationMs,
            $metadata->timeoutHit,
            $reason,
            // The whole outcome was discarded — never expose a diagnostic for a result that never really happened.
            null,
        );
        $generation->recordSolverRun($staleMetadata, $parameterSet);
        $generation->transitionTo(PlanningGenerationStatus::FAILED);
        $this->entityManager->flush();
    }

    private function writeAssignments(SolvedGeneration $solved): void
    {
        $generation = $solved->generation;
        foreach ($this->assignedUnits($solved) as [$unit, $teamMember]) {
            // DutyGroupInstance atomicity (docs/planning-solver.md §15):
            // one CP-SAT decision on the group becomes one DutyAssignment
            // per constituent Duty, all to the same candidate — the group
            // was one node in the solve, and stays one indivisible outcome
            // here.
            foreach ($unit->getDuties() as $duty) {
                $this->assignmentService->createAuto($generation, $solved->snapshot, $duty, $teamMember);
            }
        }

        $generation->recordSolverRun($solved->metadata, $solved->parameterSet);
        $generation->transitionTo(PlanningGenerationStatus::COMPLETED);

        $planningPeriod = $generation->getPlanningPeriod();
        if ($planningPeriod->getStatus()->canTransitionTo(PlanningPeriodStatus::GENERATED)) {
            $planningPeriod->transitionTo(PlanningPeriodStatus::GENERATED);
        }
        // A PUBLISHED PlanningPeriod's status is deliberately left
        // untouched (its own transition graph never allows going back to
        // GENERATED, docs/allocation-algorithm.md §18) — this generation
        // still exists as its own valid historical record either way.

        $this->entityManager->flush();
    }

    private function persistFailedOutcome(PlanningGeneration $generation, SolverRunMetadata $metadata, SolverParameterSet $parameterSet): void
    {
        $generation->recordSolverRun($metadata, $parameterSet);
        $generation->transitionTo(PlanningGenerationStatus::FAILED);
        // Never any DutyAssignment for a FAILED outcome (docs/decisions.md
        // D106) — only the generation's own audit metadata is written.
        $this->entityManager->flush();
    }
}
