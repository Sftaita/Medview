<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Planning;
use App\Repository\PlanningRepository;
use App\Security\Voter\PlanningVoter;
use App\Service\PlanningStatistics;
use App\Service\PlanningStatisticsService;
use App\Service\StatisticsGroup;
use App\Service\StatisticsMemberRow;
use App\Service\StatisticsScope;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Read-only duty counts by weekday, two perimeters (docs/decisions.md
 * D132): "this period" and "cumulative" — same authorization as
 * `/result`, anyone who can VIEW the Planning (members may read
 * statistics too, only reassignment/publication are manager-only).
 */
final class PlanningStatisticsController
{
    public function __construct(
        private readonly PlanningRepository $planningRepository,
        private readonly PlanningStatisticsService $statisticsService,
        private readonly AuthorizationCheckerInterface $authorizationChecker,
    ) {
    }

    #[Route('/api/plannings/{planningStableId}/statistics', name: 'api_planning_statistics', methods: ['GET'])]
    public function get(string $planningStableId): JsonResponse
    {
        $planning = $this->resolvePlanning($planningStableId);
        if (!$this->authorizationChecker->isGranted(PlanningVoter::VIEW, $planning)) {
            throw new AccessDeniedHttpException('You do not have access to this Planning.');
        }

        $statistics = $this->statisticsService->forPlanning($planning);

        return new JsonResponse($this->toArray($statistics));
    }

    private function resolvePlanning(string $stableId): Planning
    {
        $planning = $this->planningRepository->findOneByStableId($stableId);
        if (null === $planning) {
            throw new NotFoundHttpException('Planning not found.');
        }

        return $planning;
    }

    /**
     * @return array<string, mixed>
     */
    private function toArray(PlanningStatistics $statistics): array
    {
        return [
            'currentPeriod' => $this->scopeToArray($statistics->currentPeriod),
            'cumulative' => $this->scopeToArray($statistics->cumulative),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function scopeToArray(StatisticsScope $scope): array
    {
        return [
            'startsAt' => $scope->startsAt->format('Y-m-d'),
            'endsAt' => $scope->endsAt->format('Y-m-d'),
            'groups' => array_map($this->groupToArray(...), $scope->groups),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function groupToArray(StatisticsGroup $group): array
    {
        return [
            'groupStableId' => $group->groupStableId,
            'groupLabel' => $group->groupLabel,
            'members' => array_map($this->memberToArray(...), $group->members),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function memberToArray(StatisticsMemberRow $row): array
    {
        return [
            'teamMemberStableId' => $row->teamMemberStableId,
            'firstName' => $row->firstName,
            'lastName' => $row->lastName,
            'countsByWeekday' => $row->countsByWeekday,
            'countsByFamily' => $row->countsByFamily,
            'total' => $row->total,
        ];
    }
}
