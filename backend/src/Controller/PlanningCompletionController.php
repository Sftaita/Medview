<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Exception\PlanningJobInProgressException;
use App\Repository\PlanningRepository;
use App\Security\Voter\PlanningVoter;
use App\Service\PlanningJobPresenter;
use App\Service\PlanningJobService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * "Compléter automatiquement" (docs/decisions.md D145): fills only the
 * holes of the current calendar, every existing assignment kept as is.
 * Queued like a generation since D149 (202 + a PlanningJob the screen
 * follows) — the business rule itself is PlanningCompletionService's,
 * unchanged, run by the worker. PlanningVoter::MANAGE_CALENDAR.
 */
final class PlanningCompletionController
{
    public function __construct(
        private readonly PlanningRepository $planningRepository,
        private readonly PlanningJobService $jobService,
        private readonly PlanningJobPresenter $jobPresenter,
        private readonly AuthorizationCheckerInterface $authorizationChecker,
    ) {
    }

    #[Route('/api/plannings/{planningStableId}/complete', name: 'api_planning_complete', methods: ['POST'])]
    public function complete(string $planningStableId, #[CurrentUser] User $user): JsonResponse
    {
        $planning = $this->planningRepository->findOneByStableId($planningStableId);
        if (null === $planning) {
            throw new NotFoundHttpException('Planning not found.');
        }
        if (!$this->authorizationChecker->isGranted(PlanningVoter::MANAGE_CALENDAR, $planning)) {
            throw new AccessDeniedHttpException('Only a manager of this Planning can complete its calendar.');
        }

        try {
            $job = $this->jobService->requestCompletion($planning, $user);
        } catch (PlanningJobInProgressException $exception) {
            return new JsonResponse([
                'error' => 'job_in_progress',
                'message' => $exception->getMessage(),
                'job' => null !== $exception->activeJob ? $this->jobPresenter->toArray($exception->activeJob) : null,
            ], 409);
        }

        return new JsonResponse(['job' => $this->jobPresenter->toArray($job)], 202);
    }
}
