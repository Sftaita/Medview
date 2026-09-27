<?php

declare(strict_types=1);

namespace App\Service;

use App\Eligibility\DutyUnit;
use App\Entity\DutyAssignment;
use App\Entity\DutyAssignmentEvent;
use App\Entity\Planning;
use App\Entity\PlanningGeneration;
use App\Entity\PlanningLine;
use App\Entity\PlanningPeriodStatus;
use App\Entity\PlanningSnapshot;
use App\Entity\PlanningTeamMember;
use App\Entity\SolverParameterSet;
use App\Entity\User;
use App\Exception\NoSolverParameterSetException;
use App\Exception\PlanningCompletionStaleException;
use App\Exception\PlanningGenerationInProgressException;
use App\Fairness\DutyAssignmentEdge;
use App\Fairness\OptimizationProblem;
use App\Fairness\OptimizationResult;
use App\Fairness\PlanningSolver;
use App\Fairness\SolverStatus;
use App\Repository\DutyAssignmentRepository;
use App\Repository\PlanningGenerationRepository;
use App\Repository\PlanningLineRepository;
use App\Repository\PlanningSnapshotRepository;
use App\Repository\PlanningTeamMemberRepository;
use App\Repository\SolverParameterSetRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * "Compléter automatiquement" (docs/decisions.md D145): fills the holes of
 * the current calendar — and only them. Every unit that currently has an
 * assignee becomes a `fixedAssignment` of the solve: never changed, never
 * reconsidered, whoever put it there (the solver or a manager). This is
 * not REPAIR (docs/allocation-algorithm.md §11, still not implemented): no
 * existing assignment can ever move, so there is no change cost to
 * minimize — it is the GENERATE phase order run over a problem whose
 * decided part is frozen, so the holes go to whoever the fairness phases
 * favour *given* the load already held.
 *
 * Written into the line's current COMPLETED generation (D125), never a new
 * one: the calendar keeps one source of truth, stable ids and history. Each
 * filled Duty gets a new AUTO DutyAssignment and a DutyAssignmentEvent
 * (previous = null, author = the manager who asked).
 *
 * Eligibility: the problem is the one the generation's frozen snapshot
 * produces (targets, dimensions, conflicts, rest policy — never
 * recomputed), narrowed by the *live* rules for each hole
 * (ReassignmentCandidateService::assignabilityError(), the same check a
 * manual reassignment uses): someone who declared an unavailability since
 * the generation, or whose current assignments now conflict, is excluded.
 *
 * Concurrency: the same session lock as the generation launcher (never
 * both at once on a Planning), and the persistence step re-reads the
 * calendar under the CalendarWriteLock — if anything the solve relied on
 * changed meanwhile, nothing is written (PlanningCompletionStaleException).
 */
final class PlanningCompletionService
{
    /** Same namespace as PlanningGenerationLauncher on purpose: a completion and a generation never run together. */
    private const ENGINE_LOCK_NAMESPACE = 7351;

    public function __construct(
        private readonly PlanningLineRepository $lineRepository,
        private readonly PlanningGenerationRepository $generationRepository,
        private readonly PlanningSnapshotRepository $snapshotRepository,
        private readonly SolverParameterSetRepository $parameterSetRepository,
        private readonly DutyAssignmentRepository $assignmentRepository,
        private readonly PlanningTeamMemberRepository $teamMemberRepository,
        private readonly EligibilityMatrixBuilder $matrixBuilder,
        private readonly FairnessContextBuilder $contextBuilder,
        private readonly OptimizationProblemBuilder $problemBuilder,
        private readonly PlanningSolver $solver,
        private readonly ReassignmentCandidateService $candidateService,
        private readonly DutyAssignmentService $assignmentService,
        private readonly CalendarWriteLock $calendarWriteLock,
        private readonly EntityManagerInterface $entityManager,
        private readonly WorkHeartbeat $heartbeat,
    ) {
    }

    /**
     * @return list<CompletionLineResult> one per active line
     *
     * @throws PlanningGenerationInProgressException a generation or another completion is running on this Planning
     * @throws NoSolverParameterSetException
     * @throws PlanningCompletionStaleException      nothing was written
     */
    public function complete(Planning $planning, User $author): array
    {
        if (!$this->tryLock($planning)) {
            throw new PlanningGenerationInProgressException();
        }

        try {
            $parameterSet = $this->parameterSetRepository->findLatest();
            if (null === $parameterSet) {
                throw new NoSolverParameterSetException();
            }

            /** @var list<array{line: PlanningLine, generation: PlanningGeneration, snapshot: PlanningSnapshot, problem: OptimizationProblem, holes: array<string, DutyUnit>, fixed: array<string, string>, result: OptimizationResult}> $plans */
            $plans = [];
            $results = [];
            foreach ($this->lineRepository->findByPlanning($planning) as $line) {
                if (!$line->isActive()) {
                    continue;
                }

                $plan = $this->solveLine($line, $parameterSet);
                if ($plan instanceof CompletionLineResult) {
                    $results[] = $plan;
                    continue;
                }
                $plans[] = $plan;
            }

            if ([] !== $plans) {
                array_push($results, ...$this->persist($planning, $plans, $author));
            }

            return $this->inLineOrder($planning, $results);
        } finally {
            $this->unlock($planning);
        }
    }

    /**
     * @return CompletionLineResult|array{line: PlanningLine, generation: PlanningGeneration, snapshot: PlanningSnapshot, problem: OptimizationProblem, holes: array<string, DutyUnit>, fixed: array<string, string>, result: OptimizationResult}
     */
    private function solveLine(PlanningLine $line, SolverParameterSet $parameterSet): CompletionLineResult|array
    {
        $this->heartbeat->beat();
        $generation = $this->generationRepository->findMostRecentCompletedByPlanningPeriod($line->getPlanningPeriod());
        $snapshot = null !== $generation ? $this->snapshotRepository->findOneByGeneration($generation) : null;
        if (null === $generation || null === $snapshot) {
            return new CompletionLineResult($line, 'not_generated');
        }

        $matrix = $this->matrixBuilder->build($snapshot);
        $problem = $this->problemBuilder->build($this->contextBuilder->build($snapshot, $matrix), $parameterSet);

        [$fixed, $holes] = $this->splitUnits($problem, $this->currentByDutyId($generation));
        if ([] === $holes) {
            return new CompletionLineResult($line, 'nothing_to_complete');
        }

        $excluded = [];
        foreach ($holes as $unitKey => $unit) {
            foreach ($matrix->getForDutyUnit($unit) as $stintStableId => $eligibility) {
                if (!$eligibility->eligible) {
                    continue;
                }
                $member = $this->teamMemberRepository->findOneByStableId((string) $stintStableId);
                if (null === $member || null !== $this->candidateService->assignabilityError($generation, $unit->getDuties(), $member)) {
                    $excluded[] = new DutyAssignmentEdge($unitKey, (string) $stintStableId);
                }
            }
        }

        $constrained = $problem->withFixedAssignments($fixed, $excluded);
        $result = $this->solver->solve($constrained);

        if (!$this->isUsable($result)) {
            return new CompletionLineResult($line, 'solver_failed', \count($holes), 0, $this->countRequired($holes), $result->strictSolverStatus, $result->partialSolverStatus);
        }

        return ['line' => $line, 'generation' => $generation, 'snapshot' => $snapshot, 'problem' => $constrained, 'holes' => $holes, 'fixed' => $fixed, 'result' => $result];
    }

    /**
     * Fixed = every unit whose duties all currently have the same assignee;
     * hole = every unit with no current assignment at all. A unit in neither
     * state (an incoherent block, which no application path produces — the
     * publication preflight reports it, D133) is left out of both: kept as
     * is, never "completed".
     *
     * @param array<int, DutyAssignment> $currentByDutyId
     *
     * @return array{0: array<string, string>, 1: array<string, DutyUnit>}
     */
    private function splitUnits(OptimizationProblem $problem, array $currentByDutyId): array
    {
        $fixed = [];
        $holes = [];

        foreach ([...$problem->getRequiredDutyUnits(), ...$problem->getOptionalDutyUnits()] as $unit) {
            $members = [];
            $assignedDuties = 0;
            foreach ($unit->getDuties() as $duty) {
                $assignment = $currentByDutyId[(int) $duty->getId()] ?? null;
                if (null !== $assignment) {
                    ++$assignedDuties;
                    $members[(string) $assignment->getTeamMember()->getStableId()] = true;
                }
            }

            if (0 === $assignedDuties) {
                $holes[$unit->getStableKey()] = $unit;
            } elseif ($assignedDuties === \count($unit->getDuties()) && 1 === \count($members)) {
                $fixed[$unit->getStableKey()] = (string) array_key_first($members);
            }
        }

        return [$fixed, $holes];
    }

    /**
     * One transaction for every line: either the whole completion is
     * written, or nothing is.
     *
     * @param non-empty-list<array{line: PlanningLine, generation: PlanningGeneration, snapshot: PlanningSnapshot, problem: OptimizationProblem, holes: array<string, DutyUnit>, fixed: array<string, string>, result: OptimizationResult}> $plans
     *
     * @return list<CompletionLineResult>
     *
     * @throws PlanningCompletionStaleException
     */
    private function persist(Planning $planning, array $plans, User $author): array
    {
        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();
        try {
            $this->calendarWriteLock->acquire($planning);
            $occurredAt = new \DateTimeImmutable();
            $results = [];

            foreach ($plans as $plan) {
                $generation = $plan['generation'];
                // Re-read under the lock: exactly the state the solve was given, or nothing is written.
                [$fixedNow, $holesNow] = $this->splitUnits($plan['problem'], $this->currentByDutyId($generation));
                if ($fixedNow !== $plan['fixed'] || array_keys($holesNow) !== array_keys($plan['holes'])) {
                    throw new PlanningCompletionStaleException();
                }

                $wasPublished = PlanningPeriodStatus::PUBLISHED === $generation->getPlanningPeriod()->getStatus();
                $filled = [];
                foreach ($plan['result']->assignments as $edge) {
                    $this->heartbeat->beat();
                    $unit = $plan['holes'][$edge->dutyUnitStableKey] ?? null;
                    if (null === $unit) {
                        continue; // a fixed unit: already exactly this assignee, never rewritten
                    }

                    $member = $this->teamMemberRepository->findOneByStableId($edge->sourceTeamMemberStableId);
                    if (!$member instanceof PlanningTeamMember) {
                        throw new \LogicException(\sprintf('The solver returned an unknown TeamMember stableId "%s".', $edge->sourceTeamMemberStableId));
                    }
                    // Live revalidation at write time, against everything written so far in this transaction too.
                    if (null !== $this->candidateService->assignabilityError($generation, $unit->getDuties(), $member)) {
                        throw new PlanningCompletionStaleException();
                    }

                    foreach ($unit->getDuties() as $duty) {
                        $new = $this->assignmentService->createAuto($generation, $plan['snapshot'], $duty, $member);
                        $this->entityManager->persist(new DutyAssignmentEvent($planning, $generation, $duty, null, $new, $author, $wasPublished, $occurredAt));
                    }
                    $this->entityManager->flush();
                    $filled[$edge->dutyUnitStableKey] = true;
                }

                $remaining = array_diff_key($plan['holes'], $filled);
                $results[] = new CompletionLineResult(
                    $plan['line'],
                    'completed',
                    \count($plan['holes']),
                    \count($filled),
                    $this->countRequired($remaining),
                    $plan['result']->strictSolverStatus,
                    $plan['result']->partialSolverStatus,
                );
            }

            $connection->commit();

            return $results;
        } catch (\Throwable $exception) {
            $connection->rollBack();
            throw $exception;
        }
    }

    /**
     * @return array<int, DutyAssignment>
     */
    private function currentByDutyId(PlanningGeneration $generation): array
    {
        $byDutyId = [];
        foreach ($this->assignmentRepository->findForGenerations([$generation]) as $assignment) {
            $byDutyId[(int) $assignment->getDuty()->getId()] = $assignment;
        }

        return $byDutyId;
    }

    /**
     * @param array<string, DutyUnit> $units
     */
    private function countRequired(array $units): int
    {
        return \count(array_filter($units, static fn (DutyUnit $unit): bool => $unit->isRequired()));
    }

    private function isUsable(OptimizationResult $result): bool
    {
        if (\in_array($result->strictSolverStatus, [SolverStatus::OPTIMAL, SolverStatus::FEASIBLE], true)) {
            return true;
        }

        return SolverStatus::UNSATISFIABLE === $result->strictSolverStatus
            && \in_array($result->partialSolverStatus, [SolverStatus::OPTIMAL, SolverStatus::FEASIBLE], true);
    }

    /**
     * @param list<CompletionLineResult> $results
     *
     * @return list<CompletionLineResult>
     */
    private function inLineOrder(Planning $planning, array $results): array
    {
        $position = [];
        foreach ($this->lineRepository->findByPlanning($planning) as $index => $line) {
            $position[(int) $line->getId()] = $index;
        }
        usort($results, static fn (CompletionLineResult $a, CompletionLineResult $b): int => $position[(int) $a->line->getId()] <=> $position[(int) $b->line->getId()]);

        return $results;
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
