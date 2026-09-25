<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\ReassignDutyRequest;
use App\Entity\Duty;
use App\Entity\PlanningPeriodStatus;
use App\Entity\User;
use App\Exception\DutyNotGeneratedException;
use App\Exception\InvalidReassignmentCandidateException;
use App\Exception\PlanningGenerationNotSnapshottedException;
use App\Exception\StaleReassignmentException;
use App\Repository\DutyRepository;
use App\Repository\PlanningTeamMemberRepository;
use App\Security\Voter\PlanningVoter;
use App\Service\DutyReassignmentService;
use App\Service\ReassignmentBlockDuty;
use App\Service\ReassignmentCandidate;
use App\Service\ReassignmentCandidateService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * The dynamic calendar's write surface (docs/decisions.md D131): who could
 * take a Duty (or its whole atomic block) and, once chosen, actually
 * saving that choice. Both actions require PlanningVoter::MANAGE_CALENDAR
 * — a plain member stays read-only (§48 of the spec). All business logic
 * (block detection, live eligibility, atomic write, revalidation,
 * concurrency) lives in ReassignmentCandidateService/DutyReassignmentService;
 * this controller only authorizes, resolves stable ids and serializes.
 */
final class DutyReassignmentController
{
    public function __construct(
        private readonly DutyRepository $dutyRepository,
        private readonly PlanningTeamMemberRepository $teamMemberRepository,
        private readonly ReassignmentCandidateService $candidateService,
        private readonly DutyReassignmentService $reassignmentService,
        private readonly AuthorizationCheckerInterface $authorizationChecker,
    ) {
    }

    #[Route('/api/plannings/{planningStableId}/duties/{dutyStableId}/reassignment-candidates', name: 'api_duty_reassignment_candidates', methods: ['GET'])]
    public function candidates(string $planningStableId, string $dutyStableId): JsonResponse
    {
        $duty = $this->resolveDuty($planningStableId, $dutyStableId);

        try {
            $view = $this->candidateService->forDuty($duty);
        } catch (DutyNotGeneratedException $exception) {
            return new JsonResponse(['error' => 'duty_not_generated', 'message' => $exception->getMessage()], 409);
        }

        return new JsonResponse([
            'groupInstanceStableId' => $view->groupInstanceStableId,
            'blockDuties' => array_map($this->blockDutyToArray(...), $view->blockDuties),
            'generationStableId' => $view->generationStableId,
            'currentTeamMemberStableId' => $view->currentTeamMemberStableId,
            'candidates' => array_map($this->candidateToArray(...), $view->candidates),
        ]);
    }

    #[Route('/api/plannings/{planningStableId}/duties/{dutyStableId}/reassign', name: 'api_duty_reassign', methods: ['POST'])]
    public function reassign(string $planningStableId, string $dutyStableId, Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $duty = $this->resolveDuty($planningStableId, $dutyStableId);

        [$dto, $errorResponse] = $this->deserialize($request);
        if (null !== $errorResponse) {
            return $errorResponse;
        }

        $teamMember = $this->teamMemberRepository->findOneByStableId($dto->teamMemberStableId);
        if (null === $teamMember) {
            throw new NotFoundHttpException('Team member not found.');
        }

        $wasPublished = PlanningPeriodStatus::PUBLISHED === $duty->getPlanningPeriod()->getStatus();

        try {
            $this->reassignmentService->reassign($duty, $teamMember, $dto->expectedCurrentTeamMemberStableId, $user, $wasPublished);
        } catch (DutyNotGeneratedException $exception) {
            return new JsonResponse(['error' => 'duty_not_generated', 'message' => $exception->getMessage()], 409);
        } catch (StaleReassignmentException $exception) {
            return new JsonResponse(['error' => 'stale_reassignment', 'message' => $exception->getMessage()], 409);
        } catch (InvalidReassignmentCandidateException $exception) {
            return new JsonResponse(['error' => 'invalid_candidate', 'message' => $exception->getMessage()], 409);
        } catch (PlanningGenerationNotSnapshottedException $exception) {
            return new JsonResponse(['error' => 'generation_not_snapshotted', 'message' => $exception->getMessage()], 409);
        }

        return new JsonResponse(['status' => 'reassigned'], 200);
    }

    private function resolveDuty(string $planningStableId, string $dutyStableId): Duty
    {
        $duty = $this->dutyRepository->findOneByStableId($dutyStableId);
        if (null === $duty || (string) $duty->getPlanningPeriod()->getTeam()->getPlanning()->getStableId() !== $planningStableId) {
            throw new NotFoundHttpException('Duty not found.');
        }

        if (!$this->authorizationChecker->isGranted(PlanningVoter::MANAGE_CALENDAR, $duty->getPlanningPeriod()->getTeam()->getPlanning())) {
            throw new AccessDeniedHttpException('Only the creator or an OWNER/ADMIN of this Planning can modify its calendar.');
        }

        return $duty;
    }

    /**
     * @return array{0: ?ReassignDutyRequest, 1: ?JsonResponse}
     */
    private function deserialize(Request $request): array
    {
        try {
            $raw = json_decode($request->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [null, new JsonResponse(['error' => 'invalid_json', 'message' => 'The request body is not valid JSON.'], 400)];
        }

        $dto = new ReassignDutyRequest();
        $dto->teamMemberStableId = (string) ($raw['teamMemberStableId'] ?? '');
        $dto->expectedCurrentTeamMemberStableId = null !== ($raw['expectedCurrentTeamMemberStableId'] ?? null)
            ? (string) $raw['expectedCurrentTeamMemberStableId']
            : null;

        if ('' === $dto->teamMemberStableId) {
            return [null, new JsonResponse(['error' => 'validation_failed', 'violations' => ['teamMemberStableId' => 'This value should not be blank.']], 422)];
        }

        return [$dto, null];
    }

    /**
     * @return array<string, mixed>
     */
    private function blockDutyToArray(ReassignmentBlockDuty $duty): array
    {
        return [
            'dutyStableId' => $duty->dutyStableId,
            'date' => $duty->date,
            'startsAt' => $duty->startsAt,
            'endsAt' => $duty->endsAt,
            'dutyTypeName' => $duty->dutyTypeName,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function candidateToArray(ReassignmentCandidate $candidate): array
    {
        return [
            'teamMemberStableId' => $candidate->teamMemberStableId,
            'firstName' => $candidate->firstName,
            'lastName' => $candidate->lastName,
            'selectable' => $candidate->selectable,
            'isCurrent' => $candidate->isCurrent,
            'blockingReasons' => $candidate->blockingReasons,
        ];
    }
}
