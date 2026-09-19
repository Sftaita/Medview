<?php

declare(strict_types=1);

namespace App\Controller;

use App\Eligibility\EligibilityExclusion;
use App\Eligibility\EligibilityMatrix;
use App\Entity\Duty;
use App\Entity\PlanningGeneration;
use App\Repository\PlanningGenerationRepository;
use App\Repository\PlanningSnapshotRepository;
use App\Security\Voter\PlanningTeamRoleVoter;
use App\Service\EligibilityMatrixBuilder;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Read-only audit view of the DutyUnit × PlanningSnapshotMember eligibility
 * matrix for one PlanningGeneration (docs/eligibility.md §Endpoint).
 * OWNER/ADMIN only — unlike PlanningGenerationController's read endpoints
 * (open to any member via TEAM_VIEW_PLANNING), this is explicitly an audit
 * tool, not a general-purpose read.
 */
final class EligibilityController
{
    public function __construct(
        private readonly PlanningGenerationRepository $generationRepository,
        private readonly PlanningSnapshotRepository $snapshotRepository,
        private readonly EligibilityMatrixBuilder $matrixBuilder,
        private readonly AuthorizationCheckerInterface $authorizationChecker,
    ) {
    }

    #[Route('/api/planning-generations/{stableId}/eligibility', name: 'api_planning_generation_eligibility', methods: ['GET'])]
    public function get(string $stableId): JsonResponse
    {
        $generation = $this->resolveGeneration($stableId);

        if (!$this->authorizationChecker->isGranted(PlanningTeamRoleVoter::MANAGE_PLANNING, $generation->getPlanningPeriod()->getTeam())) {
            throw new AccessDeniedHttpException('Only an OWNER or ADMIN of this team can inspect the eligibility matrix.');
        }

        $snapshot = $this->snapshotRepository->findOneByGeneration($generation);
        if (null === $snapshot) {
            throw new NotFoundHttpException('This PlanningGeneration has no snapshot yet.');
        }

        $matrix = $this->matrixBuilder->build($snapshot);

        return new JsonResponse($this->matrixToArray($generation, $matrix));
    }

    private function resolveGeneration(string $stableId): PlanningGeneration
    {
        $generation = $this->generationRepository->findOneByStableId($stableId);
        if (null === $generation) {
            throw new NotFoundHttpException('PlanningGeneration not found.');
        }

        return $generation;
    }

    /**
     * Never exposes Doctrine entities or the DutyUnit/EligibilityResult
     * value objects directly — a hand-built read model, same convention as
     * PlanningGenerationController::snapshotToArray().
     *
     * @return array<string, mixed>
     */
    private function matrixToArray(PlanningGeneration $generation, EligibilityMatrix $matrix): array
    {
        $eligiblePairs = 0;
        $excludedPairs = 0;

        $dutyUnits = [];
        foreach ($matrix->getDutyUnits() as $dutyUnit) {
            $candidates = [];
            foreach ($matrix->getForDutyUnit($dutyUnit) as $memberStableId => $result) {
                $result->eligible ? ++$eligiblePairs : ++$excludedPairs;

                $candidates[] = [
                    'memberStableId' => $memberStableId,
                    'eligible' => $result->eligible,
                    'structuralOpportunity' => $result->structuralOpportunity,
                    'preferred' => $result->preferred,
                    'exclusions' => array_map(
                        static fn (EligibilityExclusion $exclusion): array => [
                            'reason' => $exclusion->reason->value,
                            'tier' => $exclusion->tier->value,
                            'context' => $exclusion->context,
                        ],
                        $result->exclusions,
                    ),
                ];
            }

            $dutyUnits[] = [
                'stableKey' => $dutyUnit->getStableKey(),
                'dates' => array_values(array_unique(array_map(
                    static fn (Duty $duty): string => $duty->getLocalDate()->format('Y-m-d'),
                    $dutyUnit->getDuties(),
                ))),
                'grouped' => $dutyUnit->isGrouped(),
                'candidates' => $candidates,
            ];
        }

        return [
            'generationStableId' => (string) $generation->getStableId(),
            'summary' => [
                'dutyUnitCount' => \count($dutyUnits),
                'candidateCount' => \count($matrix->getCandidates()),
                'eligiblePairs' => $eligiblePairs,
                'excludedPairs' => $excludedPairs,
            ],
            'dutyUnits' => $dutyUnits,
        ];
    }
}
