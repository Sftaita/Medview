<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Exception\InvitationEmailMismatchException;
use App\Exception\InvitationNotUsableException;
use App\Repository\UserRepository;
use App\Service\ConsumedInvitation;
use App\Service\TeamInvitationService;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * The invitee's side of an invitation link. The raw token in the path is
 * the only credential: it is never stored (only its hash is), never logged,
 * and answers say nothing about other invitations, teams or accounts
 * beyond the one invitation the token designates.
 */
final class InvitationController
{
    public function __construct(
        private readonly TeamInvitationService $invitationService,
        private readonly UserRepository $userRepository,
        #[Autowire(service: 'limiter.invitation_lookup')]
        private readonly RateLimiterFactory $lookupLimiter,
    ) {
    }

    /**
     * Public. Everything the registration form needs to prefill itself.
     * `accountExists` is only revealed to the holder of the emailed token
     * (who is, by construction, the owner of that mailbox) so the UI can
     * ask them to log in instead of registering.
     */
    #[Route('/api/invitations/{token}', name: 'api_invitation_show', methods: ['GET'])]
    public function show(string $token, Request $request): JsonResponse
    {
        $limit = $this->lookupLimiter->create($request->getClientIp())->consume(1);
        if (!$limit->isAccepted()) {
            return TooManyRequestsResponse::from($limit);
        }

        try {
            $invitation = $this->invitationService->resolveUsable($token);
        } catch (InvitationNotUsableException $exception) {
            return self::notUsable($exception);
        }

        $inviter = $invitation->getInvitedBy();
        $team = $invitation->getPlanningTeam();

        return new JsonResponse([
            'email' => $invitation->getEmail(),
            'proposedFirstName' => $invitation->getProposedFirstName(),
            'proposedLastName' => $invitation->getProposedLastName(),
            'teamName' => $team->getName(),
            'planningName' => $team->getPlanning()->getName(),
            'inviterName' => trim($inviter->getFirstName().' '.$inviter->getLastName()),
            'expiresAt' => $invitation->getExpiresAt()->format(\DATE_ATOM),
            'accountExists' => null !== $this->userRepository->findOneByEmail($invitation->getEmail()),
        ]);
    }

    /**
     * Authenticated. For an invitee whose account already exists (created
     * after the invitation was sent): attaches it to the team(s) without
     * creating any second User.
     */
    #[Route('/api/invitations/{token}/accept', name: 'api_invitation_accept', methods: ['POST'])]
    public function accept(string $token, Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $limit = $this->lookupLimiter->create($request->getClientIp())->consume(1);
        if (!$limit->isAccepted()) {
            return TooManyRequestsResponse::from($limit);
        }

        try {
            $joined = $this->invitationService->acceptAsUser($token, $user);
        } catch (InvitationNotUsableException $exception) {
            return self::notUsable($exception);
        } catch (InvitationEmailMismatchException) {
            return new JsonResponse(['error' => 'invitation_email_mismatch', 'message' => 'This invitation was sent to a different email address than your account\'s.'], 403);
        }

        return new JsonResponse(['joinedTeams' => array_map(static fn (ConsumedInvitation $c): array => $c->toArray(), $joined)]);
    }

    private static function notUsable(InvitationNotUsableException $exception): JsonResponse
    {
        return new JsonResponse(
            ['error' => $exception->reason, 'message' => 'This invitation cannot be used.'],
            InvitationNotUsableException::NOT_FOUND === $exception->reason ? 404 : 410,
        );
    }
}
