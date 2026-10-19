<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Planning;
use App\Repository\PlanningRepository;
use App\Security\Voter\PlanningVoter;
use App\Service\PlanningCycleResolver;
use App\Service\PlanningStatistics;
use App\Service\PlanningStatisticsService;
use App\Service\StatisticsGroup;
use App\Service\StatisticsMemberRow;
use App\Service\StatisticsScope;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Read-only duty counts (docs/decisions.md D132, D201), for one selected
 * planning period:
 *
 *  - `period`: the selected period and its lines, each with its publication
 *    state (PUBLISHED, UNPUBLISHED, NOT_GENERATED);
 *  - `currentPeriod`: that period's groups, each marked PUBLISHED or
 *    UNPUBLISHED (provisional);
 *  - `cumulative`: the published line periods of periods 1 to the selected
 *    one — never a provisional duty;
 *  - `provisional`: the unpublished line periods with a generation over the
 *    same periods, apart.
 *
 * `?period={stableId}`; required on a planning with several periods (422
 * `period_required` — never a guess), optional on a planning with a single
 * one, as before. Anyone who can VIEW the planning may read it, but only a
 * manager (MANAGE_CALENDAR) sees what is not published: for a plain member,
 * unpublished groups are left out and `provisional` is null.
 */
final class PlanningStatisticsController
{
    public function __construct(
        private readonly PlanningRepository $planningRepository,
        private readonly PlanningStatisticsService $statisticsService,
        private readonly PlanningCycleResolver $cycleResolver,
        private readonly AuthorizationCheckerInterface $authorizationChecker,
    ) {
    }

    #[Route('/api/plannings/{planningStableId}/statistics', name: 'api_planning_statistics', methods: ['GET'])]
    public function get(string $planningStableId, Request $request): JsonResponse
    {
        $planning = $this->resolvePlanning($planningStableId);
        if (!$this->authorizationChecker->isGranted(PlanningVoter::VIEW, $planning)) {
            throw new AccessDeniedHttpException('You do not have access to this Planning.');
        }

        $cycle = $this->cycleResolver->requiredFromRequest($planning, $request);
        $statistics = $this->statisticsService->forPlanning($planning, $cycle, $this->authorizationChecker->isGranted(PlanningVoter::MANAGE_CALENDAR, $planning));

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
        $cycle = $statistics->cycle;

        return [
            'period' => null === $cycle ? null : [
                'stableId' => (string) $cycle->getStableId(),
                'number' => $cycle->getNumber(),
                'startsAt' => $cycle->getStartsAt()->format('Y-m-d'),
                'endsAt' => $cycle->getEndsAt()->format('Y-m-d'),
                'lines' => array_map(static fn (array $line): array => ['lineStableId' => $line['line'], 'lineName' => $line['name'], 'publication' => $line['publication']], $statistics->lines),
            ],
            'currentPeriod' => $this->scopeToArray($statistics->currentPeriod),
            'cumulative' => $this->scopeToArray($statistics->cumulative),
            'provisional' => null === $statistics->provisional ? null : $this->scopeToArray($statistics->provisional),
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
            'throughPeriodNumber' => $scope->throughPeriodNumber,
            'periodNumbers' => $scope->periodNumbers,
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
            'publication' => $group->publication,
            'provisional' => StatisticsGroup::UNPUBLISHED === $group->publication,
            'periodNumbers' => $group->periodNumbers,
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
            'countsByDutyType' => $row->countsByDutyType,
            'weightedLoad' => $row->weightedLoad,
        ];
    }
}
