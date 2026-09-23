<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Planning;
use App\Entity\PlanningLine;
use App\Entity\PlanningPeriodStatus;
use App\Entity\RestPolicyOptions;
use App\Entity\User;
use App\Exception\NoActivePlanningRuleSetException;
use App\Exception\NoSolverParameterSetException;
use App\Exception\PlanningGenerationAlreadySnapshottedException;
use App\Exception\PlanningGenerationConcurrentSolveException;
use App\Exception\PlanningGenerationInProgressException;
use App\Exception\PlanningNotLaunchableException;
use App\Exception\StalePlanningGenerationDataException;
use App\Repository\DutyRepository;
use App\Repository\PlanningLineRepository;
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
 */
final class PlanningGenerationLauncher
{
    /** Namespace of the advisory lock keyed by planning id (any constant unique to this use). */
    private const ADVISORY_LOCK_NAMESPACE = 7351;

    public function __construct(
        private readonly PlanningCollectionStatusService $statusService,
        private readonly PlanningLineRepository $lineRepository,
        private readonly PlanningTeamMemberRepository $teamMemberRepository,
        private readonly DutyRepository $dutyRepository,
        private readonly PlanningRuleSetRepository $ruleSetRepository,
        private readonly SolverParameterSetRepository $parameterSetRepository,
        private readonly PlanningGenerationService $generationService,
        private readonly PlanningSnapshotService $snapshotService,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function preflight(Planning $planning): PlanningGenerationPreflight
    {
        $collection = $this->statusService->status($planning);

        $lines = [];
        $blockers = [];
        $warnings = [];

        if (null === $this->parameterSetRepository->findLatest()) {
            $blockers[] = new PreflightIssue(PreflightIssueCode::NO_SOLVER_PARAMETER_SET);
        }

        foreach ($this->lineRepository->findByPlanning($planning) as $line) {
            if (!$line->isActive()) {
                continue;
            }

            $period = $line->getPlanningPeriod();
            $readiness = new LaunchLineReadiness(
                $line,
                \count($this->teamMemberRepository->findIntersecting($line->getPlanningTeam(), $period->getStartsAt(), $period->getEndsAt())),
                \count($this->dutyRepository->findByPlanningPeriod($period)),
                $period->getStatus(),
                null !== $this->ruleSetRepository->findActive($line->getPlanningTeam()),
            );
            $lines[] = $readiness;

            if (!$readiness->hasActiveRuleSet) {
                $blockers[] = new PreflightIssue(PreflightIssueCode::NO_ACTIVE_RULE_SET, $line);
            }
            if (0 === $readiness->dutyCount) {
                $blockers[] = new PreflightIssue(PreflightIssueCode::NO_DUTIES, $line);
            }
            if (\in_array($readiness->periodStatus, [PlanningPeriodStatus::PUBLISHED, PlanningPeriodStatus::ARCHIVED], true)) {
                $blockers[] = new PreflightIssue(PreflightIssueCode::PERIOD_LOCKED, $line);
            }
            if (PlanningPeriodStatus::VALIDATED === $readiness->periodStatus) {
                $warnings[] = new PreflightIssue(PreflightIssueCode::VALIDATION_WILL_BE_INVALIDATED, $line);
            }
            if (0 === $readiness->memberCount) {
                $warnings[] = new PreflightIssue(PreflightIssueCode::LINE_WITHOUT_MEMBERS, $line);
            }
        }

        if ($collection->pendingCount() > 0) {
            $warnings[] = new PreflightIssue(PreflightIssueCode::PENDING_MEMBERS);
        }
        if (null !== $collection->deadlineOverdueDays) {
            $warnings[] = new PreflightIssue(PreflightIssueCode::DEADLINE_PASSED);
        }

        return new PlanningGenerationPreflight($collection, $lines, $blockers, $warnings);
    }

    /**
     * @return list<LaunchLineResult> one per active line, in display order
     *
     * @throws PlanningNotLaunchableException        a blocker of the preflight applies
     * @throws PlanningGenerationInProgressException another launch of this planning is running
     */
    public function launch(Planning $planning, User $launchedBy): array
    {
        if (!$this->tryLock($planning)) {
            throw new PlanningGenerationInProgressException();
        }

        try {
            $preflight = $this->preflight($planning);
            if (!$preflight->canGenerate()) {
                throw new PlanningNotLaunchableException($preflight);
            }

            $results = [];
            foreach ($preflight->lines as $readiness) {
                $results[] = $this->runLine($readiness->line, $launchedBy);
            }

            return $results;
        } finally {
            $this->unlock($planning);
        }
    }

    private function runLine(PlanningLine $line, User $launchedBy): LaunchLineResult
    {
        // The same three steps the per-period endpoints expose, unchanged. Default rest policy:
        // both disabled (D105) — the per-generation options are not part of this façade.
        $generation = $this->generationService->create($line->getPlanningPeriod(), $launchedBy, RestPolicyOptions::none());

        try {
            $snapshot = $this->snapshotService->createSnapshot($generation);
        } catch (NoActivePlanningRuleSetException|PlanningGenerationAlreadySnapshottedException) {
            return new LaunchLineResult($line, $generation, null, null, 'snapshot_failed');
        }

        try {
            $result = $this->generationService->generate($generation);
        } catch (NoSolverParameterSetException) {
            return new LaunchLineResult($line, $generation, $snapshot, null, 'no_solver_parameter_set');
        } catch (StalePlanningGenerationDataException) {
            return new LaunchLineResult($line, $generation, $snapshot, null, 'stale_generation_data');
        } catch (PlanningGenerationConcurrentSolveException) {
            return new LaunchLineResult($line, $generation, $snapshot, null, 'generation_not_solvable');
        }

        return new LaunchLineResult($line, $generation, $snapshot, $result);
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
