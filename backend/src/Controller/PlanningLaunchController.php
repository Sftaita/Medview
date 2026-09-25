<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Planning;
use App\Entity\User;
use App\Entity\UserAvailabilityType;
use App\Exception\PlanningGenerationInProgressException;
use App\Exception\PlanningNotLaunchableException;
use App\Repository\PlanningRepository;
use App\Security\Voter\PlanningVoter;
use App\Service\LaunchLineReadiness;
use App\Service\LaunchLineResult;
use App\Service\PlanningGenerationLauncher;
use App\Service\PlanningGenerationPreflight;
use App\Service\PreflightIssue;
use App\Service\RestPolicyRequestParser;
use App\Service\UnsatReportPresenter;
use App\Fairness\CoverageStatus;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Planning-level "Générer le planning" (docs/decisions.md D129): the
 * preflight, and the launch that drives the existing per-line pipeline
 * (create → snapshot → solve). Authorization (PLANNING_GENERATE) and
 * status-code mapping only — every step is PlanningGenerationLauncher and
 * the services it calls.
 *
 * Synchronous, like POST /api/planning-generations/{id}/solve (D106): the
 * response carries the outcome. A generation is *never* refused because
 * some members have not confirmed or because the availability deadline is
 * passed — only for a technical impossibility (409 `not_launchable`).
 */
final class PlanningLaunchController
{
    public function __construct(
        private readonly PlanningRepository $planningRepository,
        private readonly PlanningGenerationLauncher $launcher,
        private readonly AuthorizationCheckerInterface $authorizationChecker,
        private readonly RestPolicyRequestParser $restPolicyParser,
        private readonly UnsatReportPresenter $unsatReportPresenter,
    ) {
    }

    #[Route('/api/plannings/{planningStableId}/generation-preflight', name: 'api_planning_generation_preflight', methods: ['GET'])]
    public function preflight(string $planningStableId): JsonResponse
    {
        $planning = $this->resolvePlanning($planningStableId);
        $this->denyUnlessCanGenerate($planning);

        return new JsonResponse($this->preflightToArray($planning, $this->launcher->preflight($planning)));
    }

    #[Route('/api/plannings/{planningStableId}/generations', name: 'api_planning_launch', methods: ['POST'])]
    public function launch(string $planningStableId, Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $planning = $this->resolvePlanning($planningStableId);
        $this->denyUnlessCanGenerate($planning);

        [$restPolicy, $errorResponse] = $this->restPolicyParser->parse($request);
        if (null !== $errorResponse) {
            return $errorResponse;
        }

        try {
            $results = $this->launcher->launch($planning, $user, $restPolicy);
        } catch (PlanningNotLaunchableException $exception) {
            return new JsonResponse([
                'error' => 'not_launchable',
                'message' => $exception->getMessage(),
                'blockers' => array_map($this->issueToArray(...), $exception->preflight->blockers),
            ], 409);
        } catch (PlanningGenerationInProgressException $exception) {
            return new JsonResponse(['error' => 'generation_in_progress', 'message' => $exception->getMessage()], 409);
        }

        return new JsonResponse([
            'planningStableId' => (string) $planning->getStableId(),
            'lines' => array_map($this->lineResultToArray(...), $results),
        ], 201);
    }

    private function resolvePlanning(string $stableId): Planning
    {
        $planning = $this->planningRepository->findOneByStableId($stableId);
        if (null === $planning) {
            throw new NotFoundHttpException('Planning not found.');
        }

        return $planning;
    }

    private function denyUnlessCanGenerate(Planning $planning): void
    {
        if (!$this->authorizationChecker->isGranted(PlanningVoter::GENERATE, $planning)) {
            throw new AccessDeniedHttpException('Only the creator or an OWNER/ADMIN of this Planning can generate it.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function preflightToArray(Planning $planning, PlanningGenerationPreflight $preflight): array
    {
        $collection = $preflight->collection;

        return [
            'planning' => [
                'stableId' => (string) $planning->getStableId(),
                'name' => $planning->getName(),
                'startsAt' => $planning->getStartsAt()->format('Y-m-d'),
                'endsAt' => $planning->getEndsAt()->format('Y-m-d'),
                'lastDay' => $planning->getEndsAt()->modify('-1 day')->format('Y-m-d'),
                'timezone' => $planning->getTimezone(),
            ],
            'participantCount' => $collection->participantCount(),
            'confirmedCount' => $collection->confirmedCount(),
            'pendingCount' => $collection->pendingCount(),
            'notExpectedCount' => $collection->notExpectedCount(),
            'unavailabilityCount' => $collection->unavailabilityCount(),
            'availabilityDeadline' => $collection->availabilityDeadline?->format('Y-m-d'),
            'deadlineOverdueDays' => $collection->deadlineOverdueDays,
            'lines' => array_map(static fn (LaunchLineReadiness $line): array => [
                'stableId' => (string) $line->line->getStableId(),
                'name' => $line->line->getName(),
                'type' => $line->line->getType()->value,
                'memberCount' => $line->memberCount,
                'dutyCount' => $line->dutyCount,
                'periodStatus' => $line->periodStatus->value,
                'hasActiveRuleSet' => $line->hasActiveRuleSet,
                // docs/decisions.md D137 (§10 of the spec) — one entry per
                // AllocationFamily actually referenced by this line's
                // REQUIRED units, never a hardcoded "Week-end"/"Semaine".
                // The empty-string key (units with no family) is rendered
                // by the frontend as "Sans famille".
                'familyUnitCounts' => $line->familyUnitCounts,
            ], $preflight->lines),
            'blockers' => array_map($this->issueToArray(...), $preflight->blockers),
            'warnings' => array_map($this->issueToArray(...), $preflight->warnings),
            'canGenerate' => $preflight->canGenerate(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function issueToArray(PreflightIssue $issue): array
    {
        return [
            'code' => $issue->code->value,
            'lineStableId' => null !== $issue->line ? (string) $issue->line->getStableId() : null,
            'lineName' => $issue->line?->getName(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function lineResultToArray(LaunchLineResult $line): array
    {
        $generation = $line->generation;
        $result = $line->result;

        $snapshot = null;
        if (null !== $line->snapshot) {
            $unavailable = 0;
            foreach ($line->snapshot->getMembers() as $member) {
                foreach ($member->getAvailabilityPeriods() as $period) {
                    if (UserAvailabilityType::UNAVAILABLE === $period->getType()) {
                        ++$unavailable;
                    }
                }
            }
            $snapshot = [
                'capturedAt' => $line->snapshot->getCreatedAt()->format(\DATE_ATOM),
                'memberCount' => \count($line->snapshot->getMembers()),
                'unavailableCount' => $unavailable,
            ];
        }

        return [
            'lineStableId' => (string) $line->line->getStableId(),
            'lineName' => $line->line->getName(),
            'generationStableId' => (string) $generation->getStableId(),
            'status' => $generation->getStatus()->value,
            'error' => $line->error,
            'coverageStatus' => $result?->coverageStatus->value,
            'strictSolverStatus' => $result?->strictSolverStatus->value,
            'partialSolverStatus' => $result?->partialSolverStatus?->value,
            'assignmentCount' => null !== $result ? \count($result->assignments) : null,
            'unassignedDutyCount' => null !== $result ? \count($result->unassignedDuties) : null,
            // docs/decisions.md D137: OPTIMAL vs FEASIBLE must never be
            // conflated in the UI (§20 of the spec) — the planning-level
            // façade previously dropped this, forcing a caller to the
            // per-period endpoint just to know whether optimality was
            // actually proven.
            'optimality' => $result?->optimality,
            'diagnostics' => null !== $result && CoverageStatus::INCOMPLETE === $result->coverageStatus
                ? $this->unsatReportPresenter->toArray($result->diagnostics)
                : null,
            'snapshot' => $snapshot,
        ];
    }
}
