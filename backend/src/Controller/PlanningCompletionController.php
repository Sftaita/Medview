<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\PlanningLine;
use App\Entity\User;
use App\Exception\PlanningJobInProgressException;
use App\Exception\PublishedLinesNotCompletedException;
use App\Repository\PlanningRepository;
use App\Security\Voter\PlanningVoter;
use App\Service\PlanningCycleResolver;
use App\Service\PlanningJobPresenter;
use App\Service\PlanningJobService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
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
 *
 * docs/decisions.md D223: a published (validated, archived) line is never
 * completed automatically — 409 `published_lines_not_completed` when every
 * generated line of the period is one; otherwise those lines are reported
 * as left alone by the job. Manual changes after publication are untouched
 * by this rule (reassignment, removal, republication).
 */
final class PlanningCompletionController
{
    public function __construct(
        private readonly PlanningRepository $planningRepository,
        private readonly PlanningJobService $jobService,
        private readonly PlanningJobPresenter $jobPresenter,
        private readonly AuthorizationCheckerInterface $authorizationChecker,
        private readonly PlanningCycleResolver $cycleResolver,
    ) {
    }

    #[Route('/api/plannings/{planningStableId}/complete', name: 'api_planning_complete', methods: ['POST'])]
    public function complete(string $planningStableId, Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $planning = $this->planningRepository->findOneByStableId($planningStableId);
        if (null === $planning) {
            throw new NotFoundHttpException('Planning not found.');
        }
        if (!$this->authorizationChecker->isGranted(PlanningVoter::MANAGE_CALENDAR, $planning)) {
            throw new AccessDeniedHttpException('Only a manager of this Planning can complete its calendar.');
        }

        // docs/planning-cycles.md §7.4 (lot L3): `period` (body) or ?period=, required on a planning with several.
        $cycle = $this->cycleResolver->requiredFromRequest($planning, $request);

        try {
            $job = $this->jobService->requestCompletion($planning, $user, $cycle);
        } catch (PublishedLinesNotCompletedException $exception) {
            return new JsonResponse([
                'error' => 'published_lines_not_completed',
                'message' => $exception->getMessage(),
                'lines' => array_map(static fn (PlanningLine $line): array => ['lineStableId' => (string) $line->getStableId(), 'lineName' => $line->getName()], $exception->lines),
            ], 409);
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
