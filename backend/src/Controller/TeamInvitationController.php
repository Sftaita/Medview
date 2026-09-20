<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\InviteToTeamRequest;
use App\Entity\PlanningTeam;
use App\Entity\PlanningTeamMember;
use App\Entity\TeamInvitation;
use App\Entity\User;
use App\Exception\PlanningTeamMembershipConflictException;
use App\Repository\PlanningRepository;
use App\Repository\PlanningTeamRepository;
use App\Repository\TeamInvitationRepository;
use App\Security\Voter\PlanningTeamRoleVoter;
use App\Service\InviteOutcome;
use App\Service\InviteStatus;
use App\Service\TeamInvitationService;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Serializer\Exception\ExtraAttributesException;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * "Ajouter une personne" on a team (docs/decisions.md D111). Reserved to
 * the creator of the team's Planning and to OWNER/ADMIN of that team
 * (PlanningTeamRoleVoter::INVITE). Routes follow the existing nesting
 * (plannings/{planning}/teams/{team}/...) and resolve the team through its
 * Planning, so a team of another Planning is a 404 — never confirmed.
 *
 * Business logic (existing user vs invitation, tokens, emails) lives in
 * TeamInvitationService; this controller only deserializes, authorizes and
 * shapes the response.
 */
final class TeamInvitationController
{
    public function __construct(
        private readonly PlanningRepository $planningRepository,
        private readonly PlanningTeamRepository $planningTeamRepository,
        private readonly TeamInvitationRepository $invitationRepository,
        private readonly TeamInvitationService $invitationService,
        private readonly AuthorizationCheckerInterface $authorizationChecker,
        private readonly SerializerInterface $serializer,
        private readonly ValidatorInterface $validator,
        private readonly ClockInterface $clock,
        #[Autowire(service: 'limiter.team_invitation')]
        private readonly RateLimiterFactory $inviteLimiter,
    ) {
    }

    /**
     * Team managers only: pending invitations are not visible to plain
     * members, nor to members of another team.
     */
    #[Route('/api/plannings/{planningStableId}/teams/{teamStableId}/invitations', name: 'api_team_invitation_list', methods: ['GET'])]
    public function list(string $planningStableId, string $teamStableId): JsonResponse
    {
        $team = $this->resolveManagedTeam($planningStableId, $teamStableId);

        return new JsonResponse(array_map(
            $this->invitationToArray(...),
            $this->invitationRepository->findPendingByTeam($team),
        ));
    }

    #[Route('/api/plannings/{planningStableId}/teams/{teamStableId}/invitations', name: 'api_team_invitation_create', methods: ['POST'])]
    public function create(string $planningStableId, string $teamStableId, Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $team = $this->resolveManagedTeam($planningStableId, $teamStableId);

        $limit = $this->inviteLimiter->create((string) $user->getStableId())->consume(1);
        if (!$limit->isAccepted()) {
            return TooManyRequestsResponse::from($limit);
        }

        try {
            // Unknown fields are rejected, never silently dropped (D116).
            $dto = $this->serializer->deserialize($request->getContent(), InviteToTeamRequest::class, 'json', [AbstractNormalizer::ALLOW_EXTRA_ATTRIBUTES => false]);
        } catch (ExtraAttributesException $exception) {
            return UnknownFieldsResponse::from($exception);
        } catch (\Throwable) {
            return new JsonResponse(['error' => 'invalid_json', 'message' => 'The request body is not valid JSON.'], 400);
        }

        $dto->email = trim($dto->email);

        $violations = $this->validator->validate($dto);
        if (\count($violations) > 0) {
            $errors = [];
            foreach ($violations as $violation) {
                $errors[$violation->getPropertyPath()] = $violation->getMessage();
            }

            return new JsonResponse(['error' => 'validation_failed', 'violations' => $errors], 422);
        }

        try {
            $outcome = $this->invitationService->invite($team, $user, $dto->email, $dto->firstName, $dto->lastName);
        } catch (PlanningTeamMembershipConflictException $exception) {
            return new JsonResponse(['error' => 'membership_conflict', 'message' => $exception->getMessage()], 409);
        }

        // 201 only when something was created; the two "nothing changed"
        // outcomes are a plain 200 so a client can tell them apart without
        // parsing the body.
        $created = \in_array($outcome->status, [InviteStatus::USER_ADDED, InviteStatus::INVITATION_CREATED], true);

        return new JsonResponse($this->outcomeToArray($outcome), $created ? 201 : 200);
    }

    #[Route('/api/plannings/{planningStableId}/teams/{teamStableId}/invitations/{invitationStableId}/revoke', name: 'api_team_invitation_revoke', methods: ['POST'])]
    public function revoke(string $planningStableId, string $teamStableId, string $invitationStableId): JsonResponse
    {
        $team = $this->resolveManagedTeam($planningStableId, $teamStableId);

        $invitation = $this->invitationRepository->findOneByStableId($invitationStableId);
        // Same 404 whether it does not exist or belongs to another team.
        if (null === $invitation || $invitation->getPlanningTeam() !== $team) {
            throw new NotFoundHttpException('Invitation not found.');
        }

        try {
            $this->invitationService->revoke($invitation);
        } catch (\LogicException) {
            return new JsonResponse(['error' => 'invitation_not_pending', 'message' => 'Only a pending invitation can be revoked.'], 409);
        }

        return new JsonResponse($this->invitationToArray($invitation));
    }

    private function resolveManagedTeam(string $planningStableId, string $teamStableId): PlanningTeam
    {
        $planning = $this->planningRepository->findOneByStableId($planningStableId);
        $team = $this->planningTeamRepository->findOneByStableId($teamStableId);

        if (null === $planning || null === $team || $team->getPlanning() !== $planning) {
            throw new NotFoundHttpException('PlanningTeam not found.');
        }

        if (!$this->authorizationChecker->isGranted(PlanningTeamRoleVoter::INVITE, $team)) {
            throw new AccessDeniedHttpException('Only the creator of this Planning or an OWNER/ADMIN of this team can add people to it.');
        }

        return $team;
    }

    /**
     * @return array<string, mixed>
     */
    private function outcomeToArray(InviteOutcome $outcome): array
    {
        return [
            'status' => $outcome->status->value,
            'emailSent' => $outcome->emailSent,
            'member' => null === $outcome->member ? null : $this->memberToArray($outcome->member),
            'invitation' => null === $outcome->invitation ? null : $this->invitationToArray($outcome->invitation),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function memberToArray(PlanningTeamMember $member): array
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

    /**
     * Never contains the token or its hash.
     *
     * @return array<string, mixed>
     */
    private function invitationToArray(TeamInvitation $invitation): array
    {
        return [
            'stableId' => (string) $invitation->getStableId(),
            'email' => $invitation->getEmail(),
            'firstName' => $invitation->getProposedFirstName(),
            'lastName' => $invitation->getProposedLastName(),
            'role' => $invitation->getRole()->value,
            'status' => $invitation->effectiveStatusAt($this->clock->now())->value,
            'invitedByName' => trim($invitation->getInvitedBy()->getFirstName().' '.$invitation->getInvitedBy()->getLastName()),
            'expiresAt' => $invitation->getExpiresAt()->format(\DATE_ATOM),
            'createdAt' => $invitation->getCreatedAt()->format(\DATE_ATOM),
        ];
    }
}
