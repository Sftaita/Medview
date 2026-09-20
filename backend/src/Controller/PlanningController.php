<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\CreatePlanningRequest;
use App\Dto\UpdatePlanningRequest;
use App\Entity\Planning;
use App\Entity\PlanningLine;
use App\Entity\User;
use App\Exception\OverlappingFairnessPeriodException;
use App\Repository\PlanningLineRepository;
use App\Repository\PlanningRepository;
use App\Repository\PlanningTeamMemberRepository;
use App\Security\Voter\PlanningTeamRoleVoter;
use App\Security\Voter\PlanningVoter;
use App\Service\PlanningService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * The Planning aggregate (docs/planning.md) — creation, listing, reading,
 * renaming. Any authenticated user may create a Planning; only its
 * creator may manage it afterwards (PlanningVoter::MANAGE) — a
 * PlanningTeam's OWNER/ADMIN role grants nothing here. See
 * PlanningLineController for the nested lines resource.
 */
final class PlanningController
{
    public function __construct(
        private readonly PlanningRepository $planningRepository,
        private readonly PlanningLineRepository $planningLineRepository,
        private readonly PlanningTeamMemberRepository $teamMemberRepository,
        private readonly PlanningService $planningService,
        private readonly AuthorizationCheckerInterface $authorizationChecker,
        private readonly ValidatorInterface $validator,
    ) {
    }

    #[Route('/api/plannings', name: 'api_planning_create', methods: ['POST'])]
    public function create(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        [$dto, $errorResponse] = $this->deserializeAndValidate($request);
        if (null !== $errorResponse) {
            return $errorResponse;
        }

        [$startsAt, $endsAt, $dateErrorResponse] = $this->parseDates($dto->startsAt, $dto->endsAt);
        if (null !== $dateErrorResponse) {
            return $dateErrorResponse;
        }

        try {
            $planning = $this->planningService->create($dto->name, $user, $startsAt, $endsAt, $dto->timezone, $dto->primaryTeamName);
        } catch (OverlappingFairnessPeriodException $exception) {
            return new JsonResponse(['error' => 'team_already_scheduled', 'message' => $exception->getMessage()], 409);
        }

        return new JsonResponse($this->planningToArray($planning), 201);
    }

    #[Route('/api/plannings', name: 'api_planning_list', methods: ['GET'])]
    public function list(#[CurrentUser] User $user): JsonResponse
    {
        $plannings = $this->planningRepository->findVisibleTo($user);

        return new JsonResponse(array_map($this->planningToArray(...), $plannings));
    }

    #[Route('/api/plannings/{stableId}', name: 'api_planning_get', methods: ['GET'])]
    public function get(string $stableId): JsonResponse
    {
        $planning = $this->resolvePlanning($stableId);
        $this->denyUnlessCanView($planning);

        return new JsonResponse($this->planningToArray($planning, withLines: true));
    }

    #[Route('/api/plannings/{stableId}', name: 'api_planning_update', methods: ['PATCH'])]
    public function update(string $stableId, Request $request): JsonResponse
    {
        $planning = $this->resolvePlanning($stableId);
        $this->denyUnlessCanManage($planning);

        try {
            $raw = json_decode($request->getContent(), true, flags: \JSON_THROW_ON_ERROR);
            $dto = new UpdatePlanningRequest();
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

        $this->planningService->rename($planning, $dto->name);

        return new JsonResponse($this->planningToArray($planning, withLines: true));
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

    private function denyUnlessCanManage(Planning $planning): void
    {
        if (!$this->authorizationChecker->isGranted(PlanningVoter::MANAGE, $planning)) {
            throw new AccessDeniedHttpException('Only the creator of this Planning can manage it.');
        }
    }

    /**
     * @return array{0: ?CreatePlanningRequest, 1: ?JsonResponse}
     */
    private function deserializeAndValidate(Request $request): array
    {
        try {
            $raw = json_decode($request->getContent(), true, flags: \JSON_THROW_ON_ERROR);
            $dto = new CreatePlanningRequest();
            $dto->name = (string) ($raw['name'] ?? '');
            $dto->startsAt = (string) ($raw['startsAt'] ?? '');
            $dto->endsAt = (string) ($raw['endsAt'] ?? '');
            $dto->timezone = (string) ($raw['timezone'] ?? '');
            $dto->primaryTeamName = (string) ($raw['primaryTeam']['name'] ?? '');
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
     * @return array{0: ?\DateTimeImmutable, 1: ?\DateTimeImmutable, 2: ?JsonResponse}
     */
    private function parseDates(string $startsAtRaw, string $endsAtRaw): array
    {
        $errors = [];

        try {
            $startsAt = new \DateTimeImmutable($startsAtRaw);
        } catch (\Exception) {
            $errors['startsAt'] = 'This value is not a valid date.';
        }

        try {
            $endsAt = new \DateTimeImmutable($endsAtRaw);
        } catch (\Exception) {
            $errors['endsAt'] = 'This value is not a valid date.';
        }

        if ([] !== $errors) {
            return [null, null, new JsonResponse(['error' => 'validation_failed', 'violations' => $errors], 422)];
        }

        if ($endsAt <= $startsAt) {
            return [null, null, new JsonResponse(
                ['error' => 'validation_failed', 'violations' => ['endsAt' => 'endsAt must be strictly after startsAt.']],
                422,
            )];
        }

        return [$startsAt, $endsAt, null];
    }

    /**
     * $canManage lets the frontend decide whether to show management
     * actions without ever needing to know its own stableId to compare
     * against $creatorStableId itself (docs/planning.md §12 — /api/me does
     * not currently expose User.stableId, an authentication-lot concern
     * deliberately left untouched here). Computed via the same
     * AuthorizationCheckerInterface (reads the current security token
     * itself — no explicit User parameter needed) used to actually gate
     * every write endpoint above.
     *
     * @return array<string, mixed>
     */
    private function planningToArray(Planning $planning, bool $withLines = false): array
    {
        $data = [
            'stableId' => (string) $planning->getStableId(),
            'name' => $planning->getName(),
            'creatorStableId' => (string) $planning->getCreator()->getStableId(),
            'canManage' => $this->authorizationChecker->isGranted(PlanningVoter::MANAGE, $planning),
            'startsAt' => $planning->getStartsAt()->format('Y-m-d'),
            'endsAt' => $planning->getEndsAt()->format('Y-m-d'),
            'timezone' => $planning->getTimezone(),
            'createdAt' => $planning->getCreatedAt()->format(\DATE_ATOM),
            'updatedAt' => $planning->getUpdatedAt()->format(\DATE_ATOM),
        ];

        if ($withLines) {
            $data['lines'] = array_map(
                fn (PlanningLine $line) => $this->lineToArray($line),
                $this->planningLineRepository->findByPlanning($planning),
            );
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function lineToArray(PlanningLine $line): array
    {
        $team = $line->getPlanningTeam();
        $memberCount = \count($this->teamMemberRepository->findIntersecting(
            $team,
            $line->getPlanningPeriod()->getStartsAt(),
            $line->getPlanningPeriod()->getEndsAt(),
        ));

        return [
            'stableId' => (string) $line->getStableId(),
            'name' => $line->getName(),
            'type' => $line->getType()->value,
            'position' => $line->getPosition(),
            'active' => $line->isActive(),
            'team' => [
                'stableId' => (string) $team->getStableId(),
                'name' => $team->getName(),
                // Whether the caller may add/invite people to this team
                // (creator, or OWNER/ADMIN of it) — decided server-side, the
                // frontend has no way to compute it itself.
                'canInvite' => $this->authorizationChecker->isGranted(PlanningTeamRoleVoter::INVITE, $team),
            ],
            'memberCount' => $memberCount,
            'planningPeriodStableId' => (string) $line->getPlanningPeriod()->getStableId(),
            'createdAt' => $line->getCreatedAt()->format(\DATE_ATOM),
            'updatedAt' => $line->getUpdatedAt()->format(\DATE_ATOM),
        ];
    }
}
