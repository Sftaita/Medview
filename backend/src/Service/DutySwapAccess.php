<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\DutySwapAudience;
use App\Entity\DutySwapProposal;
use App\Entity\DutySwapRequest;
use App\Entity\PlanningLine;
use App\Entity\PlanningTeamMember;
use App\Entity\User;
use App\Repository\PlanningTeamMemberRepository;
use App\Security\Voter\PlanningVoter;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Clock\ClockInterface;

/**
 * Who may see and do what in the swap workflow (docs/duty-swaps.md §5) —
 * one place, shared by the service (which enforces it) and the presenter
 * (which only offers the actions allowed):
 *
 * - a request is visible to its requester, to its named recipients, for an
 *   ALL request to the current members of its own line (never another line
 *   or planning), and read-only to the planning's managers
 *   (PlanningVoter::MANAGE_CALENDAR) — who never need to approve anything;
 * - a proposal is visible to its two participants and to the managers —
 *   another team member sees only their own;
 * - only the decider (the participant who did not author a proposal) may
 *   accept or refuse it; only its author may withdraw it; only the
 *   requester may cancel a request.
 */
final class DutySwapAccess
{
    public function __construct(
        private readonly PlanningTeamMemberRepository $teamMemberRepository,
        private readonly Security $security,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * A current member of $line: a stint in its team that has not ended
     * (an ended stint keeps its history, never new swap rights).
     */
    public function currentLineMember(PlanningLine $line, User $user): ?PlanningTeamMember
    {
        foreach ($this->currentLineMembers($line) as $member) {
            if ($member->getUser() === $user) {
                return $member;
            }
        }

        return null;
    }

    /**
     * @return list<PlanningTeamMember> one row per person (their current stint)
     */
    public function currentLineMembers(PlanningLine $line): array
    {
        $today = new \DateTimeImmutable($this->clock->now()->setTimezone(new \DateTimeZone($line->getPlanning()->getTimezone()))->format('Y-m-d'));
        $members = [];
        foreach ($this->teamMemberRepository->findByTeam($line->getPlanningTeam()) as $member) {
            if (null === $member->getMembershipEnd() || $member->getMembershipEnd() > $today) {
                $members[(int) $member->getUser()->getId()] ??= $member;
            }
        }

        return array_values($members);
    }

    public function isManager(DutySwapRequest $request): bool
    {
        return $this->security->isGranted(PlanningVoter::MANAGE_CALENDAR, $request->getPlanning());
    }

    /** Requester, named recipient, a current line member for an ALL request, an author of one of its proposals, or a manager. */
    public function canView(DutySwapRequest $request, User $user): bool
    {
        return $request->getRequester() === $user
            || $request->isRecipient($user)
            || (DutySwapAudience::ALL === $request->getAudience() && null !== $this->currentLineMember($request->getLine(), $user))
            || $this->hasProposed($request, $user)
            || $this->isManager($request);
    }

    /** Somebody this request is addressed to (by name, or as a current line member for ALL) — never its requester. */
    public function isAudience(DutySwapRequest $request, User $user): bool
    {
        if ($request->getRequester() === $user) {
            return false;
        }

        return DutySwapAudience::ALL === $request->getAudience()
            ? null !== $this->currentLineMember($request->getLine(), $user)
            : $request->isRecipient($user);
    }

    public function canViewProposal(DutySwapProposal $proposal, User $user): bool
    {
        return $proposal->getRequest()->getRequester() === $user
            || $proposal->getAuthor() === $user
            || $proposal->getCounterpart() === $user
            || $this->isManager($proposal->getRequest());
    }

    private function hasProposed(DutySwapRequest $request, User $user): bool
    {
        foreach ($request->getProposals() as $proposal) {
            if ($proposal->getAuthor() === $user || $proposal->getCounterpart() === $user) {
                return true;
            }
        }

        return false;
    }
}
