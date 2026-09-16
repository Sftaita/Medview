<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\TeamMember;
use App\Repository\TeamMemberRepository;
use App\Repository\TeamRepository;
use App\Security\Voter\TeamRoleVoter;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Deliberately minimal (D059): just enough to host the non-participation
 * admin UI on a member row (stableId, name, role). No invitations, no
 * role editing, no team creation — that stays out of scope for this lot,
 * see docs/availability.md.
 */
final class TeamMembersController
{
    public function __construct(
        private readonly TeamRepository $teamRepository,
        private readonly TeamMemberRepository $teamMemberRepository,
        private readonly AuthorizationCheckerInterface $authorizationChecker,
    ) {
    }

    #[Route('/api/teams/{teamStableId}/members', name: 'api_team_members_list', methods: ['GET'])]
    public function list(string $teamStableId): JsonResponse
    {
        $team = $this->teamRepository->findOneByStableId($teamStableId);
        if (null === $team) {
            throw new NotFoundHttpException('Team not found.');
        }

        if (!$this->authorizationChecker->isGranted(TeamRoleVoter::VIEW_TEAM, $team)) {
            throw new AccessDeniedHttpException('You are not a member of this team.');
        }

        $members = $this->teamMemberRepository->findByTeam($team);

        return new JsonResponse(array_map($this->toArray(...), $members));
    }

    /**
     * @return array<string, mixed>
     */
    private function toArray(TeamMember $member): array
    {
        return [
            'stableId' => (string) $member->getStableId(),
            'firstName' => $member->getUser()->getFirstName(),
            'lastName' => $member->getUser()->getLastName(),
            'role' => $member->getRole()->value,
            'active' => $member->getUser()->isActive(),
        ];
    }
}
