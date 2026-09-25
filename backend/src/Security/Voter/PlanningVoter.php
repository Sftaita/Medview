<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Planning;
use App\Entity\TeamMemberRole;
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

    /**
     * Subject: Planning. The availability-collection workflow (open a collection, set its deadline,
     * close it, read everyone's answers): the creator, or a current OWNER/ADMIN of any PlanningTeam of
     * the Planning (docs/decisions.md D124). Wider than MANAGE on purpose — chasing late answers is
     * team-role work, not Planning-structure work — and never granted to a plain MEMBER.
     */
    public const MANAGE_AVAILABILITY = 'PLANNING_MANAGE_AVAILABILITY';

    /**
     * Subject: Planning. The planning-level "Générer le planning" and its preflight (D129): the same
     * population as MANAGE_AVAILABILITY — the creator, or a current OWNER/ADMIN of any PlanningTeam
     * of the Planning — decided by the same rule, not a second role system. Never a plain MEMBER.
     */
    public const GENERATE = 'PLANNING_GENERATE';

    /**
     * Subject: Planning. The dynamic calendar's write actions (docs/decisions.md D131): viewing
     * reassignment candidates and saving a reassignment. Same population, same rule, as
     * MANAGE_AVAILABILITY/GENERATE — never a new authorization policy for this lot, only a new
     * named attribute over the identical creator-or-team-OWNER/ADMIN population. Never a plain MEMBER
     * (§48 of the spec: members stay read-only).
     */
    public const MANAGE_CALENDAR = 'PLANNING_MANAGE_CALENDAR';

    /**
     * Subject: Planning. The publication preflight and the publish action itself
     * (docs/decisions.md D133): same population, same rule, as MANAGE_CALENDAR — never a new
     * authorization policy for this lot either. Never a plain MEMBER (§23 of the spec).
     */
    public const PUBLISH = 'PLANNING_PUBLISH';

    /**
     * Subject: Planning. Reading/replacing a PlanningLine's weekly structure
     * (docs/decisions.md D136): same population, same rule, as
     * MANAGE_CALENDAR/PUBLISH — never a new authorization policy for this
     * lot either. Never a plain MEMBER.
     */
    public const MANAGE_LINE_STRUCTURE = 'PLANNING_MANAGE_LINE_STRUCTURE';

    /**
     * Subject: Planning. Reading/activating a PlanningLine's generation rules
     * (docs/decisions.md D137): same population, same rule, as
     * MANAGE_LINE_STRUCTURE — never a new authorization policy for this lot
     * either. Never a plain MEMBER.
     */
    public const MANAGE_RULE_SET = 'PLANNING_MANAGE_RULE_SET';

    public function __construct(
        private readonly PlanningTeamMemberRepository $teamMemberRepository,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return \in_array($attribute, [self::VIEW, self::MANAGE, self::MANAGE_AVAILABILITY, self::GENERATE, self::MANAGE_CALENDAR, self::PUBLISH, self::MANAGE_LINE_STRUCTURE, self::MANAGE_RULE_SET], true) && $subject instanceof Planning;
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

        if (self::MANAGE_AVAILABILITY === $attribute || self::GENERATE === $attribute || self::MANAGE_CALENDAR === $attribute || self::PUBLISH === $attribute || self::MANAGE_LINE_STRUCTURE === $attribute || self::MANAGE_RULE_SET === $attribute) {
            if ($subject->getCreator() === $user) {
                return true;
            }

            $membership = $this->teamMemberRepository->findOpenMembershipForUserInPlanning($subject, $user);

            return null !== $membership && \in_array($membership->getRole(), [TeamMemberRole::OWNER, TeamMemberRole::ADMIN], true);
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
