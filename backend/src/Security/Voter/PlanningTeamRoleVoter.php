<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\PlanningTeam;
use App\Entity\PlanningTeamMember;
use App\Entity\TeamMemberRole;
use App\Entity\User;
use App\Repository\PlanningTeamMemberRepository;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * PlanningTeam-scoped authorization (D012, CLAUDE.md): "who can do what in
 * this specific team" is this Voter's job, never a claim folded into
 * User::getRoles(). Extended for planning generation (docs/planning-generation.md
 * §Autorisations) alongside the availability-lot attributes it started
 * with — still not a generic team-permissions framework, one attribute per
 * real need. Deliberately separate from PlanningVoter (docs/decisions.md
 * D079): a PlanningTeam's OWNER/ADMIN role governs generations, snapshots
 * and manual assignments for *that team's line*, while Planning-level
 * structure (renaming, adding lines, managing members) stays the sole
 * responsibility of Planning::creator, per PlanningVoter. The personal
 * calendar (/api/me/calendar) needs no Voter at all: those endpoints only
 * ever act on #[CurrentUser], never accept another User as a parameter.
 */
final class PlanningTeamRoleVoter extends Voter
{
    /** Subject: PlanningTeam. True for any current (open) member, any role. */
    public const VIEW_TEAM = 'TEAM_VIEW';

    /** Subject: PlanningTeamMember (the target member). True for OWNER/ADMIN of that member's team only. */
    public const MANAGE_NON_PARTICIPATION = 'TEAM_MEMBER_MANAGE_NON_PARTICIPATION';

    /** Subject: PlanningTeamMember (the target member). True for the member themselves, or OWNER/ADMIN of their team. */
    public const VIEW_NON_PARTICIPATION = 'TEAM_MEMBER_VIEW_NON_PARTICIPATION';

    /** Subject: PlanningTeam. True for any current (open) member, any role — mirrors VIEW_TEAM (docs/planning-generation.md §Autorisations). */
    public const VIEW_PLANNING = 'TEAM_VIEW_PLANNING';

    /** Subject: PlanningTeam. True for OWNER/ADMIN only — generations, snapshots and manual assignments are a management action. */
    public const MANAGE_PLANNING = 'TEAM_MANAGE_PLANNING';

    /**
     * Subject: PlanningTeam. Add/invite people (docs/decisions.md D111): true
     * for the creator of the team's Planning (who is not necessarily a
     * member of any of its teams) and for a current OWNER/ADMIN of that
     * team. Never for a plain MEMBER, nor for anyone in a *different* team
     * of the same Planning.
     */
    public const INVITE = 'TEAM_INVITE';

    public function __construct(private readonly PlanningTeamMemberRepository $teamMemberRepository)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return match ($attribute) {
            self::VIEW_TEAM, self::VIEW_PLANNING, self::MANAGE_PLANNING, self::INVITE => $subject instanceof PlanningTeam,
            self::MANAGE_NON_PARTICIPATION, self::VIEW_NON_PARTICIPATION => $subject instanceof PlanningTeamMember,
            default => false,
        };
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        if (self::VIEW_TEAM === $attribute || self::VIEW_PLANNING === $attribute) {
            /* @var PlanningTeam $subject */
            return null !== $this->teamMemberRepository->findOpenMembership($subject, $user);
        }

        if (self::INVITE === $attribute) {
            /* @var PlanningTeam $subject */
            return $subject->getPlanning()->getCreator() === $user || $this->hasManagingRole($subject, $user);
        }

        if (self::MANAGE_PLANNING === $attribute) {
            /* @var PlanningTeam $subject */
            return $this->hasManagingRole($subject, $user);
        }

        /* @var PlanningTeamMember $subject */
        return match ($attribute) {
            self::MANAGE_NON_PARTICIPATION => $this->hasManagingRole($subject->getPlanningTeam(), $user),
            self::VIEW_NON_PARTICIPATION => $subject->getUser() === $user || $this->hasManagingRole($subject->getPlanningTeam(), $user),
            default => false,
        };
    }

    private function hasManagingRole(PlanningTeam $team, User $user): bool
    {
        $membership = $this->teamMemberRepository->findOpenMembership($team, $user);

        return null !== $membership && \in_array($membership->getRole(), [TeamMemberRole::OWNER, TeamMemberRole::ADMIN], true);
    }
}
