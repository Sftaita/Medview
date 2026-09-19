<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\CreateDutyAssignmentRequest;
use App\Entity\DutyAssignment;
use App\Entity\PlanningGeneration;
use App\Exception\DuplicateDutyAssignmentException;
use App\Exception\InvalidDutyAssignmentException;
use App\Exception\PlanningGenerationNotSnapshottedException;
use App\Repository\DutyRepository;
use App\Repository\PlanningGenerationRepository;
use App\Repository\PlanningTeamMemberRepository;
use App\Security\Voter\PlanningTeamRoleVoter;
use App\Service\DutyAssignmentService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Manual duty assignments within a PlanningGeneration (docs/planning-generation.md
 * §17-18). Only OWNER/ADMIN may create one (PlanningTeamRoleVoter::MANAGE_PLANNING)
 * — the underlying invariants (Duty/TeamMember consistency, no duplicate)
 * are enforced by DutyAssignmentService, not here. No eligibility check
 * (availability, spacing, fairness) exists yet — a later lot's
 * EligibilityService.
 */
final class DutyAssignmentController
{
    public function __construct(
        private readonly PlanningGenerationRepository $generationRepository,
        private readonly DutyRepository $dutyRepository,
        private readonly PlanningTeamMemberRepository $teamMemberRepository,
        private readonly DutyAssignmentService $service,
        private readonly AuthorizationCheckerInterface $authorizationChecker,
        private readonly ValidatorInterface $validator,
    ) {
    }

    #[Route('/api/planning-generations/{generationStableId}/assignments', name: 'api_duty_assignment_create', methods: ['POST'])]
    public function create(string $generationStableId, Request $request): JsonResponse
    {
        $generation = $this->resolveGeneration($generationStableId);

        if (!$this->authorizationChecker->isGranted(PlanningTeamRoleVoter::MANAGE_PLANNING, $generation->getPlanningPeriod()->getTeam())) {
            throw new AccessDeniedHttpException('Only an OWNER or ADMIN of this team can create duty assignments.');
        }

        [$dto, $errorResponse] = $this->deserializeAndValidate($request);
        if (null !== $errorResponse) {
            return $errorResponse;
        }

        $duty = $this->dutyRepository->findOneByStableId($dto->dutyStableId);
        if (null === $duty) {
            throw new NotFoundHttpException('Duty not found.');
        }

        $teamMember = $this->teamMemberRepository->findOneByStableId($dto->teamMemberStableId);
        if (null === $teamMember) {
            throw new NotFoundHttpException('Team member not found.');
        }

        try {
            $assignment = $this->service->createManual($generation, $duty, $teamMember, $dto->locked);
        } catch (PlanningGenerationNotSnapshottedException $exception) {
            return new JsonResponse(['error' => 'generation_not_snapshotted', 'message' => $exception->getMessage()], 409);
        } catch (InvalidDutyAssignmentException $exception) {
            return new JsonResponse(['error' => 'invalid_assignment', 'message' => $exception->getMessage()], 422);
        } catch (DuplicateDutyAssignmentException $exception) {
            return new JsonResponse(['error' => 'duplicate_assignment', 'message' => $exception->getMessage()], 409);
        }

        return new JsonResponse($this->toArray($assignment), 201);
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
     * @return array{0: ?CreateDutyAssignmentRequest, 1: ?JsonResponse}
     */
    private function deserializeAndValidate(Request $request): array
    {
        try {
            $raw = json_decode($request->getContent(), true, flags: \JSON_THROW_ON_ERROR);
            $dto = new CreateDutyAssignmentRequest();
            $dto->dutyStableId = (string) ($raw['dutyStableId'] ?? '');
            $dto->teamMemberStableId = (string) ($raw['teamMemberStableId'] ?? '');
            $dto->locked = (bool) ($raw['locked'] ?? false);
        } catch (\JsonException) {
            return [null, new JsonResponse(['error' => 'invalid_json', 'message' => 'The request body is not valid JSON.'], 400)];
        }

        $violations = $this->validator->validate($dto);
        if (\count($violations) > 0) {
            $errors = [];
            foreach ($violations as $violation) {
                $errors[$violation->getPropertyPath()] = $violation->getMessage();
            }

            return [null, new JsonResponse(['error' => 'validation_failed', 'violations' => $errors], 422)];
        }

        return [$dto, null];
    }

    /**
     * @return array<string, mixed>
     */
    private function toArray(DutyAssignment $assignment): array
    {
        return [
            'stableId' => (string) $assignment->getStableId(),
            'planningGenerationStableId' => (string) $assignment->getGeneration()->getStableId(),
            'dutyStableId' => (string) $assignment->getDuty()->getStableId(),
            'teamMemberStableId' => (string) $assignment->getTeamMember()->getStableId(),
            'source' => $assignment->getSource()->value,
            'locked' => $assignment->isLocked(),
            'createdAt' => $assignment->getCreatedAt()->format(\DATE_ATOM),
            'updatedAt' => $assignment->getUpdatedAt()->format(\DATE_ATOM),
        ];
    }
}
