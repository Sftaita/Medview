<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\UpsertNonParticipationPeriodRequest;
use App\Entity\TeamMember;
use App\Entity\TeamMemberNonParticipationPeriod;
use App\Exception\OverlappingNonParticipationPeriodException;
use App\Repository\TeamMemberNonParticipationPeriodRepository;
use App\Repository\TeamMemberRepository;
use App\Repository\TeamRepository;
use App\Security\Voter\TeamRoleVoter;
use App\Service\TeamMemberNonParticipationService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Administrative non-participation windows for one TeamMember
 * (docs/availability.md) — never confused with the personal calendar
 * (PersonalCalendarController): this is scoped to a single team and only
 * OWNER/ADMIN may write it (TeamRoleVoter::MANAGE_NON_PARTICIPATION); the
 * member themselves may only read their own (TeamRoleVoter::VIEW_NON_PARTICIPATION).
 */
final class TeamMemberNonParticipationController
{
    public function __construct(
        private readonly TeamRepository $teamRepository,
        private readonly TeamMemberRepository $teamMemberRepository,
        private readonly TeamMemberNonParticipationPeriodRepository $repository,
        private readonly TeamMemberNonParticipationService $service,
        private readonly AuthorizationCheckerInterface $authorizationChecker,
        private readonly ValidatorInterface $validator,
    ) {
    }

    #[Route('/api/teams/{teamStableId}/members/{memberStableId}/non-participation', name: 'api_team_member_non_participation_list', methods: ['GET'])]
    public function list(string $teamStableId, string $memberStableId): JsonResponse
    {
        $member = $this->resolveMember($teamStableId, $memberStableId);

        if (!$this->authorizationChecker->isGranted(TeamRoleVoter::VIEW_NON_PARTICIPATION, $member)) {
            throw new AccessDeniedHttpException('You cannot view this member\'s non-participation periods.');
        }

        $periods = $this->repository->findByTeamMember($member);

        return new JsonResponse(array_map($this->toArray(...), $periods));
    }

    #[Route('/api/teams/{teamStableId}/members/{memberStableId}/non-participation', name: 'api_team_member_non_participation_create', methods: ['POST'])]
    public function create(string $teamStableId, string $memberStableId, Request $request): JsonResponse
    {
        $member = $this->resolveMember($teamStableId, $memberStableId);
        $this->denyUnlessCanManage($member);

        [$dto, $errorResponse] = $this->deserializeAndValidate($request);
        if (null !== $errorResponse) {
            return $errorResponse;
        }

        [$startsAt, $endsAt, $dateErrorResponse] = $this->parseDates($dto->startsAt, $dto->endsAt);
        if (null !== $dateErrorResponse) {
            return $dateErrorResponse;
        }

        try {
            $period = $this->service->create($member, $startsAt, $endsAt);
        } catch (OverlappingNonParticipationPeriodException $exception) {
            return new JsonResponse(['error' => 'overlapping_period', 'message' => $exception->getMessage()], 409);
        }

        return new JsonResponse($this->toArray($period), 201);
    }

    #[Route('/api/teams/{teamStableId}/members/{memberStableId}/non-participation/{stableId}', name: 'api_team_member_non_participation_update', methods: ['PATCH'])]
    public function update(string $teamStableId, string $memberStableId, string $stableId, Request $request): JsonResponse
    {
        $member = $this->resolveMember($teamStableId, $memberStableId);
        $this->denyUnlessCanManage($member);
        $period = $this->resolvePeriod($member, $stableId);

        [$dto, $errorResponse] = $this->deserializeAndValidate($request);
        if (null !== $errorResponse) {
            return $errorResponse;
        }

        [$startsAt, $endsAt, $dateErrorResponse] = $this->parseDates($dto->startsAt, $dto->endsAt);
        if (null !== $dateErrorResponse) {
            return $dateErrorResponse;
        }

        try {
            $this->service->reschedule($period, $startsAt, $endsAt);
        } catch (OverlappingNonParticipationPeriodException $exception) {
            return new JsonResponse(['error' => 'overlapping_period', 'message' => $exception->getMessage()], 409);
        }

        return new JsonResponse($this->toArray($period));
    }

    #[Route('/api/teams/{teamStableId}/members/{memberStableId}/non-participation/{stableId}', name: 'api_team_member_non_participation_delete', methods: ['DELETE'])]
    public function delete(string $teamStableId, string $memberStableId, string $stableId): Response
    {
        $member = $this->resolveMember($teamStableId, $memberStableId);
        $this->denyUnlessCanManage($member);
        $period = $this->resolvePeriod($member, $stableId);

        $this->service->delete($period);

        return new Response(status: 204);
    }

    private function resolveMember(string $teamStableId, string $memberStableId): TeamMember
    {
        $team = $this->teamRepository->findOneByStableId($teamStableId);
        if (null === $team) {
            throw new NotFoundHttpException('Team not found.');
        }

        $member = $this->teamMemberRepository->findOneByStableId($memberStableId);

        // 404 (not just "member not found") when the member exists but
        // belongs to a different team — never confirms cross-team data.
        if (null === $member || $member->getTeam() !== $team) {
            throw new NotFoundHttpException('Team member not found.');
        }

        return $member;
    }

    private function resolvePeriod(TeamMember $member, string $stableId): TeamMemberNonParticipationPeriod
    {
        $period = $this->repository->findOneByStableId($stableId);
        if (null === $period || $period->getTeamMember() !== $member) {
            throw new NotFoundHttpException('Non-participation period not found.');
        }

        return $period;
    }

    private function denyUnlessCanManage(TeamMember $member): void
    {
        if (!$this->authorizationChecker->isGranted(TeamRoleVoter::MANAGE_NON_PARTICIPATION, $member)) {
            throw new AccessDeniedHttpException('Only an OWNER or ADMIN of this team can manage non-participation periods.');
        }
    }

    /**
     * @return array{0: ?UpsertNonParticipationPeriodRequest, 1: ?JsonResponse}
     */
    private function deserializeAndValidate(Request $request): array
    {
        try {
            $raw = json_decode($request->getContent(), true, flags: \JSON_THROW_ON_ERROR);
            $upsert = new UpsertNonParticipationPeriodRequest();
            $upsert->startsAt = (string) ($raw['startsAt'] ?? '');
            $upsert->endsAt = (string) ($raw['endsAt'] ?? '');
        } catch (\JsonException) {
            return [null, new JsonResponse(['error' => 'invalid_json', 'message' => 'The request body is not valid JSON.'], 400)];
        }

        $violations = $this->validator->validate($upsert);
        if (\count($violations) > 0) {
            $errors = [];
            foreach ($violations as $violation) {
                $errors[$violation->getPropertyPath()] = $violation->getMessage();
            }

            return [null, new JsonResponse(['error' => 'validation_failed', 'violations' => $errors], 422)];
        }

        return [$upsert, null];
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
     * @return array<string, mixed>
     */
    private function toArray(TeamMemberNonParticipationPeriod $period): array
    {
        return [
            'stableId' => (string) $period->getStableId(),
            'startsAt' => $period->getStartsAt()->format(\DATE_ATOM),
            'endsAt' => $period->getEndsAt()->format(\DATE_ATOM),
            'createdAt' => $period->getCreatedAt()->format(\DATE_ATOM),
            'updatedAt' => $period->getUpdatedAt()->format(\DATE_ATOM),
        ];
    }
}
