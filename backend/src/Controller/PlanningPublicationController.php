<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Duty;
use App\Entity\Planning;
use App\Entity\PlanningTeamMember;
use App\Entity\User;
use App\Exception\PlanningAlreadyPublishedException;
use App\Exception\PlanningNotPublishableException;
use App\Exception\PlanningPublicationInProgressException;
use App\Repository\PlanningRepository;
use App\Security\Voter\PlanningVoter;
use App\Service\InconsistentPublicationGroup;
use App\Service\InvalidPublicationAssignment;
use App\Service\PlanningPublicationPreflightService;
use App\Service\PlanningPublicationService;
use App\Service\PublicationConflict;
use App\Service\PublicationLineReadiness;
use App\Service\PublicationLineResult;
use App\Service\PublicationPreflight;
use App\Service\UncoveredPublicationDuty;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * "Publier le planning" (docs/decisions.md D133): the preflight is
 * read-only (never modifies anything), the publish action always re-runs
 * it for real server-side — a GET loaded moments earlier is never trusted.
 * Both require PlanningVoter::PUBLISH (creator or team OWNER/ADMIN, same
 * population as MANAGE_CALENDAR).
 */
final class PlanningPublicationController
{
    public function __construct(
        private readonly PlanningRepository $planningRepository,
        private readonly PlanningPublicationPreflightService $preflightService,
        private readonly PlanningPublicationService $publicationService,
        private readonly AuthorizationCheckerInterface $authorizationChecker,
    ) {
    }

    #[Route('/api/plannings/{planningStableId}/publication-preflight', name: 'api_planning_publication_preflight', methods: ['GET'])]
    public function preflight(string $planningStableId): JsonResponse
    {
        $planning = $this->resolvePlanning($planningStableId);
        $this->assertCanPublish($planning);

        return new JsonResponse($this->preflightToArray($this->preflightService->check($planning)));
    }

    #[Route('/api/plannings/{planningStableId}/publish', name: 'api_planning_publish', methods: ['POST'])]
    public function publish(string $planningStableId, #[CurrentUser] User $user): JsonResponse
    {
        $planning = $this->resolvePlanning($planningStableId);
        $this->assertCanPublish($planning);

        try {
            $results = $this->publicationService->publish($planning, $user);
        } catch (PlanningPublicationInProgressException $exception) {
            return new JsonResponse(['error' => 'publication_in_progress', 'message' => $exception->getMessage()], 409);
        } catch (PlanningAlreadyPublishedException $exception) {
            return new JsonResponse(['error' => 'already_published', 'message' => $exception->getMessage()], 409);
        } catch (PlanningNotPublishableException $exception) {
            return new JsonResponse([
                'error' => 'not_publishable',
                'message' => $exception->getMessage(),
                'preflight' => $this->preflightToArray($exception->preflight),
            ], 409);
        }

        return new JsonResponse([
            'lines' => array_map($this->lineResultToArray(...), $results),
        ]);
    }

    private function resolvePlanning(string $stableId): Planning
    {
        $planning = $this->planningRepository->findOneByStableId($stableId);
        if (null === $planning) {
            throw new NotFoundHttpException('Planning not found.');
        }

        return $planning;
    }

    private function assertCanPublish(Planning $planning): void
    {
        if (!$this->authorizationChecker->isGranted(PlanningVoter::PUBLISH, $planning)) {
            throw new AccessDeniedHttpException('Only the creator or an OWNER/ADMIN of this Planning can publish it.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function preflightToArray(PublicationPreflight $preflight): array
    {
        return [
            'publishable' => $preflight->publishable,
            'lines' => array_map($this->lineReadinessToArray(...), $preflight->lines),
            'uncoveredDuties' => array_map($this->uncoveredDutyToArray(...), $preflight->uncoveredDuties),
            'inconsistentGroups' => array_map($this->inconsistentGroupToArray(...), $preflight->inconsistentGroups),
            'invalidAssignments' => array_map($this->invalidAssignmentToArray(...), $preflight->invalidAssignments),
            'conflicts' => array_map($this->conflictToArray(...), $preflight->conflicts),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function lineReadinessToArray(PublicationLineReadiness $readiness): array
    {
        return [
            'lineStableId' => (string) $readiness->line->getStableId(),
            'lineName' => $readiness->line->getName(),
            'periodStatus' => $readiness->periodStatus->value,
            'hasGeneration' => $readiness->hasGeneration,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function uncoveredDutyToArray(UncoveredPublicationDuty $item): array
    {
        return $this->dutyToArray($item->duty);
    }

    /**
     * @return array<string, mixed>
     */
    private function inconsistentGroupToArray(InconsistentPublicationGroup $item): array
    {
        return ['groupInstanceStableId' => (string) $item->group->getStableId()];
    }

    /**
     * @return array<string, mixed>
     */
    private function invalidAssignmentToArray(InvalidPublicationAssignment $item): array
    {
        return [
            'duty' => $this->dutyToArray($item->duty),
            'member' => $this->memberToArray($item->member),
            'reason' => $item->reason,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function conflictToArray(PublicationConflict $item): array
    {
        return [
            'duty' => $this->dutyToArray($item->duty),
            'member' => $this->memberToArray($item->member),
            'reason' => $item->reason,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function dutyToArray(Duty $duty): array
    {
        return [
            'dutyStableId' => (string) $duty->getStableId(),
            'date' => $duty->getLocalDate()->format('Y-m-d'),
            'dutyTypeName' => $duty->getDutyType()->getName(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function memberToArray(PlanningTeamMember $member): array
    {
        return [
            'teamMemberStableId' => (string) $member->getStableId(),
            'firstName' => $member->getUser()->getFirstName(),
            'lastName' => $member->getUser()->getLastName(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function lineResultToArray(PublicationLineResult $result): array
    {
        return [
            'lineStableId' => (string) $result->line->getStableId(),
            'lineName' => $result->line->getName(),
            'periodStatus' => $result->periodStatus->value,
            'alreadyPublished' => $result->alreadyPublished,
        ];
    }
}
