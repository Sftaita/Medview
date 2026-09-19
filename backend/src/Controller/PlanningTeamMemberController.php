<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\AddPlanningTeamMemberRequest;
use App\Entity\Planning;
use App\Entity\PlanningTeam;
use App\Entity\PlanningTeamMember;
use App\Exception\PlanningTeamMembershipConflictException;
use App\Repository\PlanningRepository;
use App\Repository\PlanningTeamMemberRepository;
use App\Repository\PlanningTeamRepository;
use App\Repository\UserRepository;
use App\Security\Voter\PlanningVoter;
use App\Service\PlanningTeamMembershipService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Membership management for one PlanningTeam (docs/decisions.md D079/D080).
 * Every write here is reserved to the Planning's creator
 * (PlanningVoter::MANAGE) — same restriction as PlanningLineController.
 * There is deliberately no team-level OWNER/ADMIN write path in v1: adding
 * or removing a member is Planning-structure work, not team-role work
 * (docs/planning.md §Autorisations) — PlanningTeamRoleVoter still governs
 * generation/snapshot/assignment authority for a team's own line, a
 * separate concern. Listing is open to anyone who can view the Planning.
 */
final class PlanningTeamMemberController
{
    public function __construct(
        private readonly PlanningRepository $planningRepository,
        private readonly PlanningTeamRepository $planningTeamRepository,
        private readonly PlanningTeamMemberRepository $planningTeamMemberRepository,
        private readonly UserRepository $userRepository,
        private readonly PlanningTeamMembershipService $membershipService,
        private readonly AuthorizationCheckerInterface $authorizationChecker,
        private readonly ValidatorInterface $validator,
    ) {
    }

    #[Route('/api/plannings/{planningStableId}/teams/{teamStableId}/members', name: 'api_planning_team_member_list', methods: ['GET'])]
    public function list(string $planningStableId, string $teamStableId): JsonResponse
    {
        [$planning, $team] = $this->resolveTeam($planningStableId, $teamStableId);
        $this->denyUnlessCanView($planning);

        $members = $this->planningTeamMemberRepository->findByTeam($team);

        return new JsonResponse(array_map($this->toArray(...), $members));
    }

    #[Route('/api/plannings/{planningStableId}/teams/{teamStableId}/members', name: 'api_planning_team_member_create', methods: ['POST'])]
    public function create(string $planningStableId, string $teamStableId, Request $request): JsonResponse
    {
        [$planning, $team] = $this->resolveTeam($planningStableId, $teamStableId);
        $this->denyUnlessCanManage($planning);

        [$dto, $errorResponse] = $this->deserializeAndValidate($request);
        if (null !== $errorResponse) {
            return $errorResponse;
        }

        $user = $this->userRepository->findOneByStableId($dto->userStableId);
        if (null === $user) {
            throw new NotFoundHttpException('User not found.');
        }

        try {
            $membershipStart = new \DateTimeImmutable($dto->membershipStart);
        } catch (\Exception) {
            return new JsonResponse(['error' => 'validation_failed', 'violations' => ['membershipStart' => 'This value is not a valid date.']], 422);
        }

        try {
            $member = $this->membershipService->addMember($team, $user, $dto->roleEnum(), $membershipStart);
        } catch (PlanningTeamMembershipConflictException $exception) {
            return new JsonResponse(['error' => 'membership_conflict', 'message' => $exception->getMessage()], 409);
        }

        return new JsonResponse($this->toArray($member), 201);
    }

    #[Route('/api/plannings/{planningStableId}/teams/{teamStableId}/members/{memberStableId}/end', name: 'api_planning_team_member_end', methods: ['POST'])]
    public function endMembership(string $planningStableId, string $teamStableId, string $memberStableId, Request $request): JsonResponse
    {
        [$planning, $team] = $this->resolveTeam($planningStableId, $teamStableId);
        $this->denyUnlessCanManage($planning);
        $member = $this->resolveMember($team, $memberStableId);

        $raw = json_decode($request->getContent() ?: '{}', true) ?? [];
        $membershipEndRaw = (string) ($raw['membershipEnd'] ?? '');

        try {
            $membershipEnd = '' !== $membershipEndRaw ? new \DateTimeImmutable($membershipEndRaw) : new \DateTimeImmutable('today');
        } catch (\Exception) {
            return new JsonResponse(['error' => 'validation_failed', 'violations' => ['membershipEnd' => 'This value is not a valid date.']], 422);
        }

        try {
            $this->membershipService->endMembership($member, $membershipEnd);
        } catch (\InvalidArgumentException|\LogicException $exception) {
            return new JsonResponse(['error' => 'validation_failed', 'violations' => ['membershipEnd' => $exception->getMessage()]], 422);
        }

        return new JsonResponse($this->toArray($member));
    }

    /**
     * @return array{0: Planning, 1: PlanningTeam}
     */
    private function resolveTeam(string $planningStableId, string $teamStableId): array
    {
        $planning = $this->planningRepository->findOneByStableId($planningStableId);
        if (null === $planning) {
            throw new NotFoundHttpException('Planning not found.');
        }

        $team = $this->planningTeamRepository->findOneByStableId($teamStableId);

        // 404 (not just "team not found") when the team exists but belongs
        // to a different Planning — never confirms cross-planning data.
        if (null === $team || $team->getPlanning() !== $planning) {
            throw new NotFoundHttpException('PlanningTeam not found.');
        }

        return [$planning, $team];
    }

    private function resolveMember(PlanningTeam $team, string $stableId): PlanningTeamMember
    {
        $member = $this->planningTeamMemberRepository->findOneByStableId($stableId);

        if (null === $member || $member->getPlanningTeam() !== $team) {
            throw new NotFoundHttpException('PlanningTeamMember not found.');
        }

        return $member;
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
            throw new AccessDeniedHttpException('Only the creator of this Planning can manage its team members.');
        }
    }

    /**
     * @return array{0: ?AddPlanningTeamMemberRequest, 1: ?JsonResponse}
     */
    private function deserializeAndValidate(Request $request): array
    {
        try {
            $raw = json_decode($request->getContent(), true, flags: \JSON_THROW_ON_ERROR);
            $dto = new AddPlanningTeamMemberRequest();
            $dto->userStableId = (string) ($raw['userStableId'] ?? '');
            $dto->role = (string) ($raw['role'] ?? 'MEMBER');
            $dto->membershipStart = (string) ($raw['membershipStart'] ?? '');
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
    private function toArray(PlanningTeamMember $member): array
    {
        return [
            'stableId' => (string) $member->getStableId(),
            'userStableId' => (string) $member->getUser()->getStableId(),
            'firstName' => $member->getUser()->getFirstName(),
            'lastName' => $member->getUser()->getLastName(),
            'role' => $member->getRole()->value,
            'membershipStart' => $member->getMembershipStart()->format('Y-m-d'),
            'membershipEnd' => $member->getMembershipEnd()?->format('Y-m-d'),
            'active' => $member->getUser()->isActive(),
        ];
    }
}
