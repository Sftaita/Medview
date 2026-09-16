<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Team;
use App\Entity\TeamMember;
use App\Entity\TeamMemberRole;
use App\Entity\User;
use App\Repository\TeamMemberRepository;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Team-scoped authorization (D012, CLAUDE.md): "who can do what in this
 * specific team" is this Voter's job, never a claim folded into
 * User::getRoles(). Extended for planning generation (docs/planning-generation.md
 * §Autorisations) alongside the availability-lot attributes it started
 * with — still not a generic team-permissions framework, one attribute per
 * real need. The personal calendar (/api/me/calendar) needs no Voter at
 * all: those endpoints only ever act on #[CurrentUser], never accept
 * another User as a parameter.
 */
final class TeamRoleVoter extends Voter
{
    /** Subject: Team. True for any current (open) member, any role. */
    public const VIEW_TEAM = 'TEAM_VIEW';

    /** Subject: TeamMember (the target member). True for OWNER/ADMIN of that member's team only. */
    public const MANAGE_NON_PARTICIPATION = 'TEAM_MEMBER_MANAGE_NON_PARTICIPATION';

    /** Subject: TeamMember (the target member). True for the member themselves, or OWNER/ADMIN of their team. */
    public const VIEW_NON_PARTICIPATION = 'TEAM_MEMBER_VIEW_NON_PARTICIPATION';

    /** Subject: Team. True for any current (open) member, any role — mirrors VIEW_TEAM (docs/planning-generation.md §Autorisations). */
    public const VIEW_PLANNING = 'TEAM_VIEW_PLANNING';

    /** Subject: Team. True for OWNER/ADMIN only — generations, snapshots and manual assignments are a management action. */
    public const MANAGE_PLANNING = 'TEAM_MANAGE_PLANNING';

    public function __construct(private readonly TeamMemberRepository $teamMemberRepository)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return match ($attribute) {
            self::VIEW_TEAM, self::VIEW_PLANNING, self::MANAGE_PLANNING => $subject instanceof Team,
            self::MANAGE_NON_PARTICIPATION, self::VIEW_NON_PARTICIPATION => $subject instanceof TeamMember,
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
            /* @var Team $subject */
            return null !== $this->teamMemberRepository->findOpenMembership($subject, $user);
        }

        if (self::MANAGE_PLANNING === $attribute) {
            /* @var Team $subject */
            return $this->hasManagingRole($subject, $user);
        }

        /* @var TeamMember $subject */
        return match ($attribute) {
            self::MANAGE_NON_PARTICIPATION => $this->hasManagingRole($subject->getTeam(), $user),
            self::VIEW_NON_PARTICIPATION => $subject->getUser() === $user || $this->hasManagingRole($subject->getTeam(), $user),
            default => false,
        };
    }

    private function hasManagingRole(Team $team, User $user): bool
    {
        $membership = $this->teamMemberRepository->findOpenMembership($team, $user);

        return null !== $membership && \in_array($membership->getRole(), [TeamMemberRole::OWNER, TeamMemberRole::ADMIN], true);
    }
}
