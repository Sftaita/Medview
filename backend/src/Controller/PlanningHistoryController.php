<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\PlanningRepository;
use App\Repository\PlanningTeamMemberRepository;
use App\Security\Voter\PlanningVoter;
use App\Service\PlanningCyclePresenter;
use App\Service\PlanningCycleResolver;
use App\Service\PlanningHistoryService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * docs/decisions.md D217 — the history of one period of a planning
 * (PlanningHistoryService), for those who manage its availabilities
 * (MANAGE_AVAILABILITY, like the availability status of its members).
 * `?period=` picks the period (default: current/next/last, as elsewhere),
 * `?member=<memberStableId>` narrows it to one participant.
 */
final class PlanningHistoryController
{
    public function __construct(
        private readonly PlanningRepository $planningRepository,
        private readonly PlanningTeamMemberRepository $teamMemberRepository,
        private readonly PlanningCycleResolver $cycleResolver,
        private readonly PlanningCyclePresenter $cyclePresenter,
        private readonly PlanningHistoryService $historyService,
        private readonly AuthorizationCheckerInterface $authorizationChecker,
    ) {
    }

    #[Route('/api/plannings/{planningStableId}/history', name: 'api_planning_history', methods: ['GET'])]
    public function history(string $planningStableId, Request $request): JsonResponse
    {
        $planning = $this->planningRepository->findOneByStableId($planningStableId);
        if (null === $planning) {
            throw new NotFoundHttpException('Planning not found.');
        }
        if (!$this->authorizationChecker->isGranted(PlanningVoter::MANAGE_AVAILABILITY, $planning)) {
            throw new AccessDeniedHttpException('Only those who manage the availabilities of this Planning read its history.');
        }
        $cycle = $this->cycleResolver->fromRequest($planning, $request);

        $member = null;
        $memberStableId = $request->query->get('member');
        if (null !== $memberStableId && '' !== $memberStableId) {
            $member = $this->teamMemberRepository->findOneByStableId((string) $memberStableId);
            if (null === $member || $member->getPlanning() !== $planning) {
                throw new NotFoundHttpException('Member not found in this Planning.');
            }
        }

        return new JsonResponse([
            'period' => $this->cyclePresenter->reference($cycle),
            'timezone' => $planning->getTimezone(),
            'member' => null === $member ? null : [
                'memberStableId' => (string) $member->getStableId(),
                'userStableId' => (string) $member->getUser()->getStableId(),
                'firstName' => $member->getUser()->getFirstName(),
                'lastName' => $member->getUser()->getLastName(),
            ],
            'entries' => $this->historyService->history($planning, $cycle, $member),
        ]);
    }
}
