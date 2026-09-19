<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Planning;
use App\Entity\PlanningTeam;
use App\Repository\PlanningRepository;
use App\Repository\PlanningTeamRepository;
use App\Security\Voter\PlanningVoter;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Read-only listing of a Planning's PlanningTeams (docs/decisions.md D079).
 * There is deliberately no team-creation endpoint here: a PlanningTeam is
 * only ever created inline by PlanningLineController when a line is added,
 * never as a standalone resource a client populates first — see
 * PlanningLineService::addLine(). Visible to anyone who can view the
 * Planning (PlanningVoter::VIEW), same as PlanningController::get().
 */
final class PlanningTeamController
{
    public function __construct(
        private readonly PlanningRepository $planningRepository,
        private readonly PlanningTeamRepository $planningTeamRepository,
        private readonly AuthorizationCheckerInterface $authorizationChecker,
    ) {
    }

    #[Route('/api/plannings/{planningStableId}/teams', name: 'api_planning_team_list', methods: ['GET'])]
    public function list(string $planningStableId): JsonResponse
    {
        $planning = $this->resolvePlanning($planningStableId);
        $this->denyUnlessCanView($planning);

        $teams = $this->planningTeamRepository->findByPlanning($planning);

        return new JsonResponse(array_map($this->toArray(...), $teams));
    }

    private function resolvePlanning(string $stableId): Planning
    {
        $planning = $this->planningRepository->findOneByStableId($stableId);
        if (null === $planning) {
            throw new NotFoundHttpException('Planning not found.');
        }

        return $planning;
    }

    private function denyUnlessCanView(Planning $planning): void
    {
        if (!$this->authorizationChecker->isGranted(PlanningVoter::VIEW, $planning)) {
            throw new AccessDeniedHttpException('You do not have access to this Planning.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function toArray(PlanningTeam $team): array
    {
        return [
            'stableId' => (string) $team->getStableId(),
            'name' => $team->getName(),
            'active' => $team->isActive(),
            'createdAt' => $team->getCreatedAt()->format(\DATE_ATOM),
            'updatedAt' => $team->getUpdatedAt()->format(\DATE_ATOM),
        ];
    }
}
