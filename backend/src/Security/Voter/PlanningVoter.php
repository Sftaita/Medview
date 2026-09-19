<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Planning;
use App\Entity\User;
use App\Repository\PlanningTeamMemberRepository;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Planning ownership (docs/planning.md §Autorisations, D071) — deliberately
 * separate from PlanningTeamRoleVoter: a PlanningTeam's OWNER/ADMIN role
 * grants no right over a Planning they did not create, even if their team
 * powers one of its PlanningLines. No collaborator/co-owner concept exists
 * yet — v1 has exactly one manager per Planning, its creator.
 */
final class PlanningVoter extends Voter
{
    /** Subject: Planning. True for the creator, or any current member of a PlanningTeam powering one of its PlanningLines. */
    public const VIEW = 'PLANNING_VIEW';

    /** Subject: Planning. True only for the creator — never merely by holding a managing role in an associated PlanningTeam. */
    public const MANAGE = 'PLANNING_MANAGE';

    public function __construct(
        private readonly PlanningTeamMemberRepository $teamMemberRepository,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return \in_array($attribute, [self::VIEW, self::MANAGE], true) && $subject instanceof Planning;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        /* @var Planning $subject */
        if (self::MANAGE === $attribute) {
            return $subject->getCreator() === $user;
        }

        if ($subject->getCreator() === $user) {
            return true;
        }

        // Membership is Planning-scoped (docs/decisions.md D079/D080): a
        // single lookup on the denormalized planning_id column tells us
        // whether $user has an open membership in *any* of this Planning's
        // PlanningTeams, with no need to enumerate its PlanningLines first.
        return null !== $this->teamMemberRepository->findOpenMembershipForUserInPlanning($subject, $user);
    }
}
