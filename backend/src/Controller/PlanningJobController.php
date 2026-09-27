<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\PlanningRepository;
use App\Security\Voter\PlanningVoter;
use App\Service\PlanningJobPresenter;
use App\Service\PlanningJobService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * The engine job the screen follows (docs/decisions.md D149): the active
 * one, or the most recent one — polled while QUEUED/RUNNING, and found
 * again after a reload, from another tab or by another manager. Anyone who
 * can view the Planning may read it (a member sees "génération en cours"
 * too); starting one stays a manager's action.
 */
final class PlanningJobController
{
    public function __construct(
        private readonly PlanningRepository $planningRepository,
        private readonly PlanningJobService $jobService,
        private readonly PlanningJobPresenter $presenter,
        private readonly AuthorizationCheckerInterface $authorizationChecker,
    ) {
    }

    #[Route('/api/plannings/{planningStableId}/jobs/latest', name: 'api_planning_job_latest', methods: ['GET'])]
    public function latest(string $planningStableId): JsonResponse
    {
        $planning = $this->planningRepository->findOneByStableId($planningStableId);
        if (null === $planning) {
            throw new NotFoundHttpException('Planning not found.');
        }
        if (!$this->authorizationChecker->isGranted(PlanningVoter::VIEW, $planning)) {
            throw new AccessDeniedHttpException('You do not have access to this Planning.');
        }

        $job = $this->jobService->latest($planning);
        if (null === $job) {
            return new JsonResponse(['job' => null]);
        }

        $body = $this->presenter->toArray($job);
        // The per-line outcome carries the solver's diagnostics (who was excluded from what, and why):
        // manager information, like the launch itself — a member only sees the job's state.
        if (!$this->authorizationChecker->isGranted(PlanningVoter::GENERATE, $planning)
            && !$this->authorizationChecker->isGranted(PlanningVoter::MANAGE_CALENDAR, $planning)) {
            $body['outcome'] = null;
        }

        return new JsonResponse(['job' => $body]);
    }
}
