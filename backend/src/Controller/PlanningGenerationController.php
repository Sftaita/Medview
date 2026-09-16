<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\PlanningGeneration;
use App\Entity\PlanningPeriod;
use App\Entity\PlanningSnapshot;
use App\Entity\Team;
use App\Entity\User;
use App\Entity\UserAvailabilityType;
use App\Exception\NoActivePlanningRuleSetException;
use App\Exception\PlanningGenerationAlreadySnapshottedException;
use App\Repository\PlanningGenerationRepository;
use App\Repository\PlanningPeriodRepository;
use App\Repository\PlanningSnapshotRepository;
use App\Repository\PlanningSnapshotRuleSetRepository;
use App\Security\Voter\TeamRoleVoter;
use App\Service\PlanningGenerationService;
use App\Service\PlanningSnapshotService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * PlanningGeneration + its PlanningSnapshot (docs/planning-generation.md).
 * Read access (TeamRoleVoter::VIEW_PLANNING) is open to any current member
 * of the team; creating a generation, snapshotting it, and (in
 * DutyAssignmentController) manually assigning a Duty within it are all
 * reserved to OWNER/ADMIN (TeamRoleVoter::MANAGE_PLANNING).
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
        private readonly AuthorizationCheckerInterface $authorizationChecker,
    ) {
    }

    #[Route('/api/planning-periods/{planningPeriodStableId}/generations', name: 'api_planning_generation_create', methods: ['POST'])]
    public function create(string $planningPeriodStableId, #[CurrentUser] User $user): JsonResponse
    {
        $planningPeriod = $this->resolvePlanningPeriod($planningPeriodStableId);
        $this->denyUnlessCanManage($planningPeriod->getTeam());

        $generation = $this->generationService->create($planningPeriod, $user);

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

    private function denyUnlessCanView(Team $team): void
    {
        if (!$this->authorizationChecker->isGranted(TeamRoleVoter::VIEW_PLANNING, $team)) {
            throw new AccessDeniedHttpException('You must be a member of this team to view its planning generations.');
        }
    }

    private function denyUnlessCanManage(Team $team): void
    {
        if (!$this->authorizationChecker->isGranted(TeamRoleVoter::MANAGE_PLANNING, $team)) {
            throw new AccessDeniedHttpException('Only an OWNER or ADMIN of this team can manage planning generations.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function generationToArray(PlanningGeneration $generation): array
    {
        $createdBy = $generation->getCreatedBy();

        return [
            'stableId' => (string) $generation->getStableId(),
            'planningPeriodStableId' => (string) $generation->getPlanningPeriod()->getStableId(),
            'status' => $generation->getStatus()->value,
            'createdByStableId' => null !== $createdBy ? (string) $createdBy->getStableId() : null,
            'createdAt' => $generation->getCreatedAt()->format(\DATE_ATOM),
            'updatedAt' => $generation->getUpdatedAt()->format(\DATE_ATOM),
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
            'summary' => [
                'memberCount' => \count($members),
                'unavailableCount' => $unavailableCount,
                'preferDutyCount' => $preferDutyCount,
                'nonParticipationCount' => $nonParticipationCount,
            ],
        ];
    }
}
