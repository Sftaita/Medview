<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\PlanningGeneration;
use App\Entity\PlanningPeriod;
use App\Entity\PlanningSnapshot;
use App\Entity\PlanningSnapshotExternalCommitment;
use App\Entity\PlanningTeam;
use App\Entity\User;
use App\Entity\UserAvailabilityType;
use App\Exception\NoActivePlanningRuleSetException;
use App\Exception\NoSolverParameterSetException;
use App\Exception\PlanningGenerationAlreadySnapshottedException;
use App\Exception\PlanningGenerationConcurrentSolveException;
use App\Exception\StalePlanningGenerationDataException;
use App\Fairness\CoverageStatus;
use App\Fairness\OptimizationResult;
use App\Repository\PlanningGenerationRepository;
use App\Repository\PlanningJobRepository;
use App\Repository\PlanningPeriodRepository;
use App\Repository\PlanningSnapshotRepository;
use App\Repository\PlanningSnapshotRuleSetRepository;
use App\Security\Voter\PlanningTeamRoleVoter;
use App\Service\PlanningGenerationService;
use App\Service\PlanningSnapshotService;
use App\Service\RestPolicyRequestParser;
use App\Service\UnsatReportPresenter;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * PlanningGeneration + its PlanningSnapshot (docs/planning-generation.md).
 * Read access (PlanningTeamRoleVoter::VIEW_PLANNING) is open to any current member
 * of the team; creating a generation, snapshotting it, and (in
 * DutyAssignmentController) manually assigning a Duty within it are all
 * reserved to OWNER/ADMIN (PlanningTeamRoleVoter::MANAGE_PLANNING).
 */
final class PlanningGenerationController
{
    public function __construct(
        private readonly PlanningPeriodRepository $planningPeriodRepository,
        private readonly PlanningGenerationRepository $generationRepository,
        private readonly PlanningSnapshotRepository $snapshotRepository,
        private readonly PlanningSnapshotRuleSetRepository $snapshotRuleSetRepository,
        private readonly PlanningGenerationService $generationService,
        private readonly PlanningSnapshotService $snapshotService,
        private readonly UnsatReportPresenter $unsatReportPresenter,
        private readonly AuthorizationCheckerInterface $authorizationChecker,
        private readonly RestPolicyRequestParser $restPolicyParser,
        private readonly PlanningJobRepository $jobRepository,
    ) {
    }

    #[Route('/api/planning-periods/{planningPeriodStableId}/generations', name: 'api_planning_generation_create', methods: ['POST'])]
    public function create(string $planningPeriodStableId, Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $planningPeriod = $this->resolvePlanningPeriod($planningPeriodStableId);
        $this->denyUnlessCanManage($planningPeriod->getTeam());

        [$restPolicy, $errorResponse] = $this->restPolicyParser->parse($request);
        if (null !== $errorResponse) {
            return $errorResponse;
        }

        $generation = $this->generationService->create($planningPeriod, $user, $restPolicy);

        return new JsonResponse($this->generationToArray($generation), 201);
    }

    #[Route('/api/planning-periods/{planningPeriodStableId}/generations', name: 'api_planning_generation_list', methods: ['GET'])]
    public function list(string $planningPeriodStableId): JsonResponse
    {
        $planningPeriod = $this->resolvePlanningPeriod($planningPeriodStableId);
        $this->denyUnlessCanView($planningPeriod->getTeam());

        $generations = $this->generationRepository->findByPlanningPeriod($planningPeriod);

        return new JsonResponse(array_map($this->generationToArray(...), $generations));
    }

    #[Route('/api/planning-generations/{stableId}', name: 'api_planning_generation_get', methods: ['GET'])]
    public function get(string $stableId): JsonResponse
    {
        $generation = $this->resolveGeneration($stableId);
        $this->denyUnlessCanView($generation->getPlanningPeriod()->getTeam());

        return new JsonResponse($this->generationToArray($generation));
    }

    #[Route('/api/planning-generations/{stableId}/snapshot', name: 'api_planning_generation_snapshot_create', methods: ['POST'])]
    public function createSnapshot(string $stableId): JsonResponse
    {
        $generation = $this->resolveGeneration($stableId);
        $this->denyUnlessCanManage($generation->getPlanningPeriod()->getTeam());

        try {
            $snapshot = $this->snapshotService->createSnapshot($generation);
        } catch (PlanningGenerationAlreadySnapshottedException $exception) {
            return new JsonResponse(['error' => 'already_snapshotted', 'message' => $exception->getMessage()], 409);
        } catch (NoActivePlanningRuleSetException $exception) {
            return new JsonResponse(['error' => 'no_active_rule_set', 'message' => $exception->getMessage()], 409);
        }

        return new JsonResponse($this->snapshotToArray($snapshot), 201);
    }

    #[Route('/api/planning-generations/{stableId}/snapshot', name: 'api_planning_generation_snapshot_get', methods: ['GET'])]
    public function getSnapshot(string $stableId): JsonResponse
    {
        $generation = $this->resolveGeneration($stableId);
        $this->denyUnlessCanView($generation->getPlanningPeriod()->getTeam());

        $snapshot = $this->snapshotRepository->findOneByGeneration($generation);
        if (null === $snapshot) {
            throw new NotFoundHttpException('This PlanningGeneration has no snapshot yet.');
        }

        return new JsonResponse($this->snapshotToArray($snapshot));
    }

    /**
     * Runs a real, synchronous solve (docs/decisions.md D106) — the only
     * business logic here is authorization + status-code mapping;
     * everything else lives in `PlanningGenerationService::generate()`
     * (CLAUDE.md: "aucune logique métier dans les contrôleurs").
     *
     * Technical endpoint since D149: no screen calls it (the UI queues a
     * PlanningJob run by the worker). Refused while the planning has an
     * active job; a request of it cut by the web time limit leaves a
     * SOLVING generation that PlanningJobRecovery fails later.
     */
    #[Route('/api/planning-generations/{stableId}/solve', name: 'api_planning_generation_solve', methods: ['POST'])]
    public function solve(string $stableId): JsonResponse
    {
        $generation = $this->resolveGeneration($stableId);
        $this->denyUnlessCanManage($generation->getPlanningPeriod()->getTeam());

        // Technical, per-period endpoint (no screen calls it — the UI queues a PlanningJob, D149):
        // never a second engine run next to the planning's queued/running job.
        if (null !== $this->jobRepository->findActiveForPlanning($generation->getPlanningPeriod()->getTeam()->getPlanning())) {
            return new JsonResponse(['error' => 'job_in_progress', 'message' => 'A generation or a completion is already in progress for this planning.'], 409);
        }

        try {
            $result = $this->generationService->generate($generation);
        } catch (PlanningGenerationConcurrentSolveException $exception) {
            return new JsonResponse(['error' => 'generation_not_solvable', 'message' => $exception->getMessage()], 409);
        } catch (NoSolverParameterSetException $exception) {
            return new JsonResponse(['error' => 'no_solver_parameter_set', 'message' => $exception->getMessage()], 409);
        } catch (StalePlanningGenerationDataException $exception) {
            return new JsonResponse(['error' => 'stale_generation_data', 'message' => $exception->getMessage()], 409);
        }

        return new JsonResponse($this->solveResultToArray($generation, $result), 200);
    }

    private function resolvePlanningPeriod(string $stableId): PlanningPeriod
    {
        $planningPeriod = $this->planningPeriodRepository->findOneByStableId($stableId);
        if (null === $planningPeriod) {
            throw new NotFoundHttpException('PlanningPeriod not found.');
        }

        return $planningPeriod;
    }

    private function resolveGeneration(string $stableId): PlanningGeneration
    {
        $generation = $this->generationRepository->findOneByStableId($stableId);
        if (null === $generation) {
            throw new NotFoundHttpException('PlanningGeneration not found.');
        }

        return $generation;
    }

    private function denyUnlessCanView(PlanningTeam $team): void
    {
        if (!$this->authorizationChecker->isGranted(PlanningTeamRoleVoter::VIEW_PLANNING, $team)) {
            throw new AccessDeniedHttpException('You must be a member of this team to view its planning generations.');
        }
    }

    private function denyUnlessCanManage(PlanningTeam $team): void
    {
        if (!$this->authorizationChecker->isGranted(PlanningTeamRoleVoter::MANAGE_PLANNING, $team)) {
            throw new AccessDeniedHttpException('Only an OWNER or ADMIN of this team can manage planning generations.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function generationToArray(PlanningGeneration $generation): array
    {
        $createdBy = $generation->getCreatedBy();
        $restPolicy = $generation->getRestPolicy();

        return [
            'stableId' => (string) $generation->getStableId(),
            'planningPeriodStableId' => (string) $generation->getPlanningPeriod()->getStableId(),
            'status' => $generation->getStatus()->value,
            'mode' => $generation->getMode()->value,
            'restPolicy' => [
                'legalMinRest' => ['enabled' => $restPolicy->legalMinRestEnabled, 'hours' => $restPolicy->legalMinRestHours],
                'teamMinRest' => ['enabled' => $restPolicy->teamMinRestEnabled, 'hours' => $restPolicy->teamMinRestHours],
            ],
            // Every field below stays null until a real solve (POST
            // .../solve, docs/decisions.md D106) has concluded for this
            // generation.
            'solverRun' => [
                'algorithmVersion' => $generation->getAlgorithmVersion(),
                'solverType' => $generation->getSolverType(),
                'solverVersion' => $generation->getSolverVersion(),
                'solverParameterSetVersion' => $generation->getSolverParameterSet()?->getVersion(),
                'seed' => $generation->getSeed(),
                'snapshotHash' => $generation->getSnapshotHash(),
                'strictSolverStatus' => $generation->getStrictSolverStatus()?->value,
                'partialSolverStatus' => $generation->getPartialSolverStatus()?->value,
                'coverageStatus' => $generation->getCoverageStatus()?->value,
                'solveDurationMs' => $generation->getSolveDurationMs(),
                'timeoutHit' => $generation->isTimeoutHit(),
                'failureReason' => $generation->getFailureReason(),
                'generatedAt' => $generation->getGeneratedAt()?->format(\DATE_ATOM),
            ],
            'createdByStableId' => null !== $createdBy ? (string) $createdBy->getStableId() : null,
            'createdAt' => $generation->getCreatedAt()->format(\DATE_ATOM),
            'updatedAt' => $generation->getUpdatedAt()->format(\DATE_ATOM),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function solveResultToArray(PlanningGeneration $generation, OptimizationResult $result): array
    {
        return [
            'generationStableId' => (string) $generation->getStableId(),
            'status' => $generation->getStatus()->value,
            'strictSolverStatus' => $result->strictSolverStatus->value,
            'partialSolverStatus' => $result->partialSolverStatus?->value,
            'coverageStatus' => $result->coverageStatus->value,
            'assignmentCount' => \count($result->assignments),
            'unassignedDutyCount' => \count($result->unassignedDuties),
            'objectiveValues' => $result->objectiveValues,
            'optimality' => $result->optimality,
            'solverMetadata' => [
                'algorithmVersion' => $generation->getAlgorithmVersion(),
                'solverType' => $generation->getSolverType(),
                'solverVersion' => $generation->getSolverVersion(),
                'solverParameterSetVersion' => $generation->getSolverParameterSet()?->getVersion(),
                'seed' => $generation->getSeed(),
                'snapshotHash' => $generation->getSnapshotHash(),
                'solveDurationMs' => $generation->getSolveDurationMs(),
                'timeoutHit' => $generation->isTimeoutHit(),
            ],
            // Never the raw CP-SAT internals — only the already-typed
            // UnsatReport model (docs/planning-solver.md §27), and only
            // when there is actually something to explain.
            'diagnostics' => CoverageStatus::INCOMPLETE === $result->coverageStatus
                ? $this->unsatReportPresenter->toArray($result->diagnostics)
                : null,
        ];
    }

    /**
     * Never exposes Doctrine entities directly (docs/planning-generation.md
     * §20) — a hand-built read model instead, including the summary counts
     * a minimal admin UI would otherwise have to re-derive itself.
     *
     * @return array<string, mixed>
     */
    private function snapshotToArray(PlanningSnapshot $snapshot): array
    {
        $members = [];
        $unavailableCount = 0;
        $preferDutyCount = 0;
        $nonParticipationCount = 0;

        foreach ($snapshot->getMembers() as $member) {
            $availabilityPeriods = [];
            foreach ($member->getAvailabilityPeriods() as $period) {
                if (UserAvailabilityType::UNAVAILABLE === $period->getType()) {
                    ++$unavailableCount;
                } else {
                    ++$preferDutyCount;
                }

                $availabilityPeriods[] = [
                    'sourceAvailabilityStableId' => (string) $period->getSourceAvailabilityStableId(),
                    'type' => $period->getType()->value,
                    // MANUAL or SURGICAL_HUB, frozen with the snapshot (docs/surgicalhub-integration.md §6.1).
                    'provenance' => $period->getProvenance()->value,
                    'startsAt' => $period->getStartsAt()->format(\DATE_ATOM),
                    'endsAt' => $period->getEndsAt()->format(\DATE_ATOM),
                ];
            }

            $nonParticipationPeriods = [];
            foreach ($member->getNonParticipationPeriods() as $period) {
                ++$nonParticipationCount;

                $nonParticipationPeriods[] = [
                    'sourceNonParticipationStableId' => (string) $period->getSourceNonParticipationStableId(),
                    'startsAt' => $period->getStartsAt()->format(\DATE_ATOM),
                    'endsAt' => $period->getEndsAt()->format(\DATE_ATOM),
                ];
            }

            $participationPeriods = [];
            foreach ($member->getParticipationPeriods() as $period) {
                $participationPeriods[] = [
                    'validFrom' => $period->getValidFrom()->format('Y-m-d'),
                    'validTo' => $period->getValidTo()?->format('Y-m-d'),
                    'participationFactor' => $period->toFloat(),
                    'changeReason' => $period->getChangeReason()->value,
                ];
            }

            $members[] = [
                'sourceTeamMemberStableId' => (string) $member->getSourceTeamMemberStableId(),
                'sourceUserStableId' => (string) $member->getSourceUserStableId(),
                'membershipStart' => $member->getMembershipStart()->format('Y-m-d'),
                'membershipEnd' => $member->getMembershipEnd()?->format('Y-m-d'),
                'role' => $member->getRole()->value,
                'participationPeriods' => $participationPeriods,
                'availabilityPeriods' => $availabilityPeriods,
                'nonParticipationPeriods' => $nonParticipationPeriods,
            ];
        }

        $ruleSet = $this->snapshotRuleSetRepository->findOneBySnapshot($snapshot);

        return [
            'generation' => $this->generationToArray($snapshot->getGeneration()),
            'capturedAt' => $snapshot->getCreatedAt()->format(\DATE_ATOM),
            'members' => $members,
            'ruleSet' => null === $ruleSet ? null : [
                'sourcePlanningRuleSetStableId' => (string) $ruleSet->getSourcePlanningRuleSetStableId(),
                'sourceVersion' => $ruleSet->getSourceVersion(),
                'configuration' => $ruleSet->getConfiguration(),
            ],
            // docs/decisions.md D161: duties the snapshot's people already held on the lines solved before this one.
            'externalCommitments' => array_map(static fn (PlanningSnapshotExternalCommitment $c): array => [
                'sourceUserStableId' => (string) $c->getSourceUserStableId(),
                'sourceLineStableId' => (string) $c->getSourceLineStableId(),
                'sourceGenerationStableId' => (string) $c->getSourceGenerationStableId(),
                'sourceDutyStableId' => (string) $c->getSourceDutyStableId(),
                'startsAt' => $c->getStartsAt()->format(\DATE_ATOM),
                'endsAt' => $c->getEndsAt()->format(\DATE_ATOM),
                'sourceLegalMinRestHours' => $c->getSourceLegalMinRestHours(),
                'sourceTeamMinRestHours' => $c->getSourceTeamMinRestHours(),
            ], $snapshot->getExternalCommitments()->toArray()),
            'summary' => [
                'memberCount' => \count($members),
                'unavailableCount' => $unavailableCount,
                'preferDutyCount' => $preferDutyCount,
                'nonParticipationCount' => $nonParticipationCount,
                'externalCommitmentCount' => $snapshot->getExternalCommitments()->count(),
            ],
        ];
    }
}
