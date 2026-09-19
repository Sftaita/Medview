<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\CreatePlanningLineRequest;
use App\Dto\UpdatePlanningLineRequest;
use App\Entity\Planning;
use App\Entity\PlanningLine;
use App\Entity\PlanningLineType;
use App\Exception\OverlappingFairnessPeriodException;
use App\Exception\PrimaryPlanningLineNotDeletableException;
use App\Repository\PlanningLineRepository;
use App\Repository\PlanningRepository;
use App\Security\Voter\PlanningVoter;
use App\Service\PlanningLineService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * PlanningLine management (docs/planning.md §2-§3, §11). Every action here
 * is reserved to the Planning's creator (PlanningVoter::MANAGE) — same
 * restriction as PlanningController, never a PlanningTeam role. The
 * PRIMARY line (created alongside the Planning itself, see
 * PlanningController::create()) is never created or deleted through this
 * controller — POST always creates a SECONDARY line, together with a
 * brand new PlanningTeam (docs/decisions.md D079), and DELETE always
 * refuses a PRIMARY one.
 */
final class PlanningLineController
{
    public function __construct(
        private readonly PlanningRepository $planningRepository,
        private readonly PlanningLineRepository $planningLineRepository,
        private readonly PlanningLineService $planningLineService,
        private readonly AuthorizationCheckerInterface $authorizationChecker,
        private readonly ValidatorInterface $validator,
    ) {
    }

    #[Route('/api/plannings/{planningStableId}/lines', name: 'api_planning_line_create', methods: ['POST'])]
    public function create(string $planningStableId, Request $request): JsonResponse
    {
        $planning = $this->resolvePlanning($planningStableId);
        $this->denyUnlessCanManage($planning);

        [$dto, $errorResponse] = $this->deserializeAndValidate($request);
        if (null !== $errorResponse) {
            return $errorResponse;
        }

        try {
            $line = $this->planningLineService->addLine($planning, $dto->name, PlanningLineType::SECONDARY);
        } catch (OverlappingFairnessPeriodException $exception) {
            return new JsonResponse(['error' => 'team_already_scheduled', 'message' => $exception->getMessage()], 409);
        }

        return new JsonResponse($this->lineToArray($line), 201);
    }

    #[Route('/api/plannings/{planningStableId}/lines/{lineStableId}', name: 'api_planning_line_update', methods: ['PATCH'])]
    public function update(string $planningStableId, string $lineStableId, Request $request): JsonResponse
    {
        $planning = $this->resolvePlanning($planningStableId);
        $this->denyUnlessCanManage($planning);
        $line = $this->resolveLine($planning, $lineStableId);

        try {
            $raw = json_decode($request->getContent(), true, flags: \JSON_THROW_ON_ERROR);
            $dto = new UpdatePlanningLineRequest();
            $dto->name = (string) ($raw['name'] ?? '');
        } catch (\JsonException) {
            return new JsonResponse(['error' => 'invalid_json', 'message' => 'The request body is not valid JSON.'], 400);
        }

        $violations = $this->validator->validate($dto);
        if (\count($violations) > 0) {
            $errors = [];
            foreach ($violations as $violation) {
                $errors[$violation->getPropertyPath()] = $violation->getMessage();
            }

            return new JsonResponse(['error' => 'validation_failed', 'violations' => $errors], 422);
        }

        $this->planningLineService->rename($line, $dto->name);

        return new JsonResponse($this->lineToArray($line));
    }

    #[Route('/api/plannings/{planningStableId}/lines/{lineStableId}', name: 'api_planning_line_delete', methods: ['DELETE'])]
    public function delete(string $planningStableId, string $lineStableId): Response
    {
        $planning = $this->resolvePlanning($planningStableId);
        $this->denyUnlessCanManage($planning);
        $line = $this->resolveLine($planning, $lineStableId);

        try {
            $this->planningLineService->deleteLine($line);
        } catch (PrimaryPlanningLineNotDeletableException $exception) {
            return new JsonResponse(['error' => 'primary_line_not_deletable', 'message' => $exception->getMessage()], 409);
        }

        return new Response(status: 204);
    }

    private function resolvePlanning(string $stableId): Planning
    {
        $planning = $this->planningRepository->findOneByStableId($stableId);
        if (null === $planning) {
            throw new NotFoundHttpException('Planning not found.');
        }

        return $planning;
    }

    private function resolveLine(Planning $planning, string $stableId): PlanningLine
    {
        $line = $this->planningLineRepository->findOneByStableId($stableId);

        // 404 (not just "line not found") when the line exists but belongs
        // to a different Planning — never confirms cross-planning data.
        if (null === $line || $line->getPlanning() !== $planning) {
            throw new NotFoundHttpException('PlanningLine not found.');
        }

        return $line;
    }

    private function denyUnlessCanManage(Planning $planning): void
    {
        if (!$this->authorizationChecker->isGranted(PlanningVoter::MANAGE, $planning)) {
            throw new AccessDeniedHttpException('Only the creator of this Planning can manage its lines.');
        }
    }

    /**
     * @return array{0: ?CreatePlanningLineRequest, 1: ?JsonResponse}
     */
    private function deserializeAndValidate(Request $request): array
    {
        try {
            $raw = json_decode($request->getContent(), true, flags: \JSON_THROW_ON_ERROR);
            $dto = new CreatePlanningLineRequest();
            $dto->name = (string) ($raw['name'] ?? '');
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
    private function lineToArray(PlanningLine $line): array
    {
        $team = $line->getPlanningTeam();

        return [
            'stableId' => (string) $line->getStableId(),
            'name' => $line->getName(),
            'type' => $line->getType()->value,
            'position' => $line->getPosition(),
            'active' => $line->isActive(),
            'team' => [
                'stableId' => (string) $team->getStableId(),
                'name' => $team->getName(),
            ],
            'planningPeriodStableId' => (string) $line->getPlanningPeriod()->getStableId(),
            'createdAt' => $line->getCreatedAt()->format(\DATE_ATOM),
            'updatedAt' => $line->getUpdatedAt()->format(\DATE_ATOM),
        ];
    }
}
