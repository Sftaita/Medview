<?php

declare(strict_types=1);

namespace App\Service;

use App\Demand\LiveDemandView;
use App\Demand\SourceHolding;
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
use App\Fairness\FairnessDimensionValues;
use App\Fairness\OptimizationProblem;
use App\Fairness\OptimizationResult;
use App\Fairness\PlanningSolver;
use App\Fairness\SolverStatus;
use App\Repository\DutyAssignmentRepository;
use App\Repository\DutyRepository;
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
 * Several lines (docs/decisions.md D161): the lines are completed one
 * after another in resolution order (PlanningLineOrder). The same person
 * may belong to several lines (D160): the holes planned on an earlier line
 * are virtual commitments for the later ones, checked with the same
 * person-level rule as a manual reassignment. The cross-line commitments
 * frozen in the generation's snapshot are NOT used here — the live
 * calendar is the truth for a completion.
 *
 * Conditional lines (docs/decisions.md D165): what a line must cover is
 * its LIVE demand — the rules its current generation froze, the source
 * holders of the current calendar, plus the holders its source line is
 * about to receive in this very completion (SourceHolding overrides) —
 * never the demand the generation had. A reinforcement that became
 * required after the generation is therefore completed; one that is not
 * required, or whose demand cannot be evaluated, never is. A superfluous
 * reinforcement somebody still holds enters the solve as a LOAD-ONLY
 * unit, fixed to its holder: not demand (targets are computed on the live
 * demand only), but a real load the fairness phases count. The population,
 * participation factors and eligibility stay the snapshot's.
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
        private readonly PlanningLineOrder $lineOrder,
        private readonly LiveDemandViewFactory $demandViewFactory,
        private readonly DutyUnitFactory $dutyUnitFactory,
        private readonly DutyRepository $dutyRepository,
        private readonly DimensionMembershipCalculator $dimensionMembershipCalculator,
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

            /** @var list<array{line: PlanningLine, generation: PlanningGeneration, snapshot: PlanningSnapshot, problem: OptimizationProblem, holes: array<string, DutyUnit>, fixed: array<string, string>, result: OptimizationResult, undetermined: int}> $plans */
            $plans = [];
            $results = [];
            // docs/decisions.md D161: one line after another, in resolution order (PlanningLineOrder). The holes
            // a line is about to fill are not written yet, but the lines after it must already treat them as
            // commitments of the people concerned — otherwise the same person could be picked on two lines at
            // once, and the write-time revalidation would reject the whole completion.
            $virtualCommitments = [];
            // docs/decisions.md D165: the live demand, seeing the holders the lines solved earlier are about to get.
            $liveDemand = $this->demandViewFactory->forPlanning($planning);
            $virtualHoldings = [];
            foreach ($this->lineOrder->activeInResolutionOrder($planning) as $line) {
                $plan = $this->solveLine($line, $parameterSet, $virtualCommitments, $liveDemand->withSourceHoldings($virtualHoldings));
                if ($plan instanceof CompletionLineResult) {
                    $results[] = $plan;
                    continue;
                }
                $plans[] = $plan;
                $planned = $this->plannedCommitments($plan);
                array_push($virtualCommitments, ...$planned);
                foreach ($planned as $commitment) {
                    $virtualHoldings[(int) $commitment->duty->getId()] = new SourceHolding(true, (string) $commitment->teamMember->getUser()->getStableId());
                }
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
     * @return CompletionLineResult|array{line: PlanningLine, generation: PlanningGeneration, snapshot: PlanningSnapshot, problem: OptimizationProblem, holes: array<string, DutyUnit>, fixed: array<string, string>, result: OptimizationResult, undetermined: int}
     */
    /**
     * @param list<PersonCommitment> $virtualCommitments fills planned on the lines solved before this one
     * @param LiveDemandView         $demand             the live demand, holders planned on earlier lines included
     */
    private function solveLine(PlanningLine $line, SolverParameterSet $parameterSet, array $virtualCommitments, LiveDemandView $demand): CompletionLineResult|array
    {
        $this->heartbeat->beat();
        $generation = $this->generationRepository->findMostRecentCompletedByPlanningPeriod($line->getPlanningPeriod());
        $snapshot = null !== $generation ? $this->snapshotRepository->findOneByGeneration($generation) : null;
        if (null === $generation || null === $snapshot) {
            return new CompletionLineResult($line, 'not_generated');
        }

        // Without the cross-line commitments frozen at generation time (D161): they may no longer hold (a
        // reassignment on another line since), and must never keep excluding someone who is free today. The
        // live check below (assignabilityError) re-applies the cross-line rule against the current calendar.
        // D165: the units are the LIVE demand's (a conditional unit only when it is required today); demand, exposure
        // and targets are computed on them alone. Population, participation and eligibility stay the snapshot's.
        $matrix = $this->matrixBuilder->build($snapshot, withFrozenExternalCommitments: false, demand: $demand);
        $currentByDutyId = $this->currentByDutyId($generation);
        [$loadOnly, $undeterminedUnitCount] = $this->conditionalLeftovers($line, $demand, $matrix->getDutyUnits(), $currentByDutyId);
        $problem = $this->problemBuilder->build($this->contextBuilder->build($snapshot, $matrix), $parameterSet)
            ->withLoadOnlyUnits($loadOnly, $this->membershipOf($loadOnly));

        [$fixed, $holes] = $this->splitUnits($problem, $currentByDutyId);
        if ([] === $holes) {
            return new CompletionLineResult($line, 'nothing_to_complete', undeterminedUnitCount: $undeterminedUnitCount);
        }

        $excluded = [];
        foreach ($holes as $unitKey => $unit) {
            foreach ($matrix->getForDutyUnit($unit) as $stintStableId => $eligibility) {
                if (!$eligibility->eligible) {
                    continue;
                }
                $member = $this->teamMemberRepository->findOneByStableId((string) $stintStableId);
                if (null === $member || null !== $this->candidateService->assignabilityError($generation, $unit->getDuties(), $member, $virtualCommitments)) {
                    $excluded[] = new DutyAssignmentEdge($unitKey, (string) $stintStableId);
                }
            }
        }

        $constrained = $problem->withFixedAssignments($fixed, $excluded);
        $result = $this->solver->solve($constrained);

        if (!$this->isUsable($result)) {
            return new CompletionLineResult($line, 'solver_failed', \count($holes), 0, $this->countRequired($holes), $result->strictSolverStatus, $result->partialSolverStatus, $undeterminedUnitCount);
        }

        return ['line' => $line, 'generation' => $generation, 'snapshot' => $snapshot, 'problem' => $constrained, 'holes' => $holes, 'fixed' => $fixed, 'result' => $result, 'undetermined' => $undeterminedUnitCount];
    }

    /**
     * The conditional units the live demand left out of the matrix
     * (docs/decisions.md D165): those somebody still holds coherently
     * (superfluous, or undetermined) become LOAD-ONLY units — their holder's
     * real load, fixed, never a decision; those nobody holds are simply not
     * completed, and the undetermined ones among them are counted so the
     * result never reads as complete.
     *
     * @param list<DutyUnit>             $demandUnits     the units of the matrix
     * @param array<int, DutyAssignment> $currentByDutyId
     *
     * @return array{0: list<DutyUnit>, 1: int}
     */
    private function conditionalLeftovers(PlanningLine $line, LiveDemandView $demand, array $demandUnits, array $currentByDutyId): array
    {
        $inMatrix = [];
        foreach ($demandUnits as $unit) {
            $inMatrix[$unit->getStableKey()] = true;
        }

        $loadOnly = [];
        $undetermined = 0;
        foreach ($this->dutyUnitFactory->fromDuties($this->dutyRepository->findByPlanningPeriod($line->getPlanningPeriod())) as $unit) {
            if (!$unit->getDuties()[0]->isConditional() || isset($inMatrix[$unit->getStableKey()])) {
                continue;
            }

            $holders = [];
            foreach ($unit->getDuties() as $duty) {
                $assignment = $currentByDutyId[(int) $duty->getId()] ?? null;
                $holders[] = null !== $assignment ? (string) $assignment->getTeamMember()->getStableId() : null;
            }
            $coherentlyHeld = !\in_array(null, $holders, true) && 1 === \count(array_unique($holders));

            if ($coherentlyHeld) {
                $loadOnly[] = DutyUnitFactory::withDemandDecision($unit, false);
            } elseif ([] === array_filter($holders) && !$demand->forUnit($unit)->determined) {
                ++$undetermined;
            }
        }

        return [$loadOnly, $undetermined];
    }

    /**
     * @param list<DutyUnit> $units
     *
     * @return array<string, FairnessDimensionValues>
     */
    private function membershipOf(array $units): array
    {
        $membership = [];
        foreach ($units as $unit) {
            $membership[$unit->getStableKey()] = $this->dimensionMembershipCalculator->forDutyUnit($unit);
        }

        return $membership;
    }

    /**
     * The holes $plan is about to fill, as commitments of their future
     * holders — for the lines solved after it (D161).
     *
     * @param array{line: PlanningLine, generation: PlanningGeneration, snapshot: PlanningSnapshot, problem: OptimizationProblem, holes: array<string, DutyUnit>, fixed: array<string, string>, result: OptimizationResult, undetermined: int} $plan
     *
     * @return list<PersonCommitment>
     */
    private function plannedCommitments(array $plan): array
    {
        $commitments = [];
        foreach ($plan['result']->assignments as $edge) {
            $unit = $plan['holes'][$edge->dutyUnitStableKey] ?? null;
            $member = null !== $unit ? $this->teamMemberRepository->findOneByStableId($edge->sourceTeamMemberStableId) : null;
            if (null === $member) {
                continue; // a fixed unit (already a real commitment, read live), or an unknown id rejected at write time
            }
            foreach ($unit->getDuties() as $duty) {
                $commitments[] = new PersonCommitment($plan['line'], $plan['generation'], $duty, $member);
            }
        }

        return $commitments;
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
     * @param non-empty-list<array{line: PlanningLine, generation: PlanningGeneration, snapshot: PlanningSnapshot, problem: OptimizationProblem, holes: array<string, DutyUnit>, fixed: array<string, string>, result: OptimizationResult, undetermined: int}> $plans
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
                // D165: the live demand again, under the lock — what the lines written just before in this
                // transaction now hold included. A reinforcement the calendar no longer requires is never written.
                $demandNow = $this->demandViewFactory->forPlanning($planning);
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
                    if ($unit->getDuties()[0]->isConditional() && !$demandNow->forUnit($unit)->required) {
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
                    $plan['undetermined'],
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
