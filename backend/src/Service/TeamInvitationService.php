<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\PlanningTeam;
use App\Entity\TeamInvitation;
use App\Entity\TeamInvitationStatus;
use App\Entity\TeamMemberRole;
use App\Entity\User;
use App\Exception\InvitationEmailMismatchException;
use App\Exception\InvitationNotUsableException;
use App\Exception\PlanningTeamMembershipConflictException;
use App\Repository\PlanningTeamMemberRepository;
use App\Repository\TeamInvitationRepository;
use App\Repository\UserRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * "Add this person to this team" (docs/decisions.md D111-D113): decides,
 * from the email alone, between adding an existing User straight away
 * (no acceptance step) and creating a TeamInvitation for someone who has
 * no account yet — and consumes invitations when the account appears.
 *
 * Nothing here ever creates a User: a User exists only through
 * UserRegistrationService. An invitation is never a half-built account.
 */
final class TeamInvitationService
{
    public function __construct(
        private readonly TeamInvitationRepository $invitationRepository,
        private readonly UserRepository $userRepository,
        private readonly PlanningTeamMemberRepository $teamMemberRepository,
        private readonly PlanningTeamMembershipService $membershipService,
        private readonly InvitationMailer $mailer,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
        #[Autowire(env: 'int:INVITATION_TTL_HOURS')]
        private readonly int $ttlHours,
    ) {
    }

    /**
     * @throws PlanningTeamMembershipConflictException when the email belongs to an existing User who already
     *                                                 has an open membership in ANOTHER team of the same Planning
     */
    public function invite(PlanningTeam $team, User $inviter, string $email, string $firstName, string $lastName): InviteOutcome
    {
        $email = TeamInvitation::normalizeEmail($email);
        $existingUser = $this->userRepository->findOneByEmail($email);

        if (null !== $existingUser) {
            return $this->addExistingUser($team, $inviter, $existingUser);
        }

        return $this->createInvitation($team, $inviter, $email, $firstName, $lastName);
    }

    /**
     * @throws \LogicException unless the invitation is PENDING
     */
    public function revoke(TeamInvitation $invitation): void
    {
        $invitation->revoke();
        $this->entityManager->flush();
    }

    /**
     * Read-only resolution of a raw token (public GET). Never leaks why
     * something is missing beyond the four documented reasons, and never
     * takes a lock.
     *
     * @throws InvitationNotUsableException
     */
    public function resolveUsable(string $rawToken): TeamInvitation
    {
        $invitation = '' === $rawToken ? null : $this->invitationRepository->findOneByTokenHash(TeamInvitation::hashToken($rawToken));

        return $this->assertUsable($invitation);
    }

    /**
     * Same as resolveUsable() but row-locks the invitation; must run inside
     * a transaction (registration / authenticated accept).
     *
     * @throws InvitationNotUsableException
     */
    public function resolveUsableForUpdate(string $rawToken): TeamInvitation
    {
        $invitation = '' === $rawToken ? null : $this->invitationRepository->findOneByTokenHashForUpdate(TeamInvitation::hashToken($rawToken));

        return $this->assertUsable($invitation);
    }

    /**
     * A logged-in user accepts an invitation link — the path for someone
     * whose account was created after the invitation was sent
     * (docs/decisions.md D113). The authenticated account's email must be
     * the invitation's: the token alone is not enough, and a token alone
     * cannot attach someone else's account to a team.
     *
     * @return list<ConsumedInvitation>
     *
     * @throws InvitationNotUsableException
     * @throws InvitationEmailMismatchException
     */
    public function acceptAsUser(string $rawToken, User $user): array
    {
        return $this->entityManager->wrapInTransaction(function () use ($rawToken, $user): array {
            $invitation = $this->resolveUsableForUpdate($rawToken);

            if ($invitation->getEmail() !== TeamInvitation::normalizeEmail($user->getEmail())) {
                throw new InvitationEmailMismatchException();
            }

            return $this->consumePendingFor($user);
        });
    }

    /**
     * Consumes every usable PENDING invitation addressed to $user's email,
     * creating the corresponding memberships. Must run inside a
     * transaction: the invitations are row-locked so two concurrent
     * submissions serialize, and the caller's commit makes account +
     * memberships + ACCEPTED all-or-nothing.
     *
     * The caller must already have proven control of the mailbox (by
     * holding a valid token): consuming *all* invitations for the address
     * on that single proof is what lets one registration join several
     * teams (docs/decisions.md D113).
     *
     * An invitation is left PENDING (not consumed) when its membership is
     * impossible — the user already has an open membership in another team
     * of the same Planning — and lapsed ones are flipped to EXPIRED.
     *
     * @return list<ConsumedInvitation>
     */
    public function consumePendingFor(User $user): array
    {
        $now = $this->clock->now();
        $consumed = [];

        foreach ($this->invitationRepository->findPendingByEmailForUpdate(TeamInvitation::normalizeEmail($user->getEmail())) as $invitation) {
            if (!$invitation->isUsableAt($now)) {
                $invitation->markExpired();
                continue;
            }

            $team = $invitation->getPlanningTeam();
            $member = $this->teamMemberRepository->findOpenMembership($team, $user);

            if (null === $member) {
                try {
                    $member = $this->membershipService->addMember($team, $user, $invitation->getRole(), $this->today());
                } catch (PlanningTeamMembershipConflictException) {
                    $this->logger->notice('Invitation {id} left pending: the user already has an open membership in another team of the same planning.', ['id' => (string) $invitation->getStableId()]);
                    continue;
                }
            }

            $invitation->accept($user, $now);
            $consumed[] = new ConsumedInvitation($invitation, $member);
        }

        $this->entityManager->flush();

        return $consumed;
    }

    private function addExistingUser(PlanningTeam $team, User $inviter, User $user): InviteOutcome
    {
        $current = $this->teamMemberRepository->findOpenMembership($team, $user);
        if (null !== $current) {
            return new InviteOutcome(InviteStatus::ALREADY_MEMBER, member: $current);
        }

        try {
            $member = $this->membershipService->addMember($team, $user, TeamMemberRole::MEMBER, $this->today());
        } catch (UniqueConstraintViolationException) {
            // Lost a race against another request adding the same user to
            // this Planning: the partial unique index is the real guard.
            throw new PlanningTeamMembershipConflictException();
        }

        $emailSent = $this->mailer->sendAddedToTeam($user, $inviter, $team);

        return new InviteOutcome(InviteStatus::USER_ADDED, member: $member, emailSent: $emailSent);
    }

    private function createInvitation(PlanningTeam $team, User $inviter, string $email, string $firstName, string $lastName): InviteOutcome
    {
        $now = $this->clock->now();
        $pending = $this->invitationRepository->findPendingByTeamAndEmail($team, $email);

        if (null !== $pending) {
            if ($pending->isUsableAt($now)) {
                return new InviteOutcome(InviteStatus::INVITATION_ALREADY_PENDING, invitation: $pending);
            }

            // Lapsed but still stored as PENDING: flipped and flushed on its
            // own first, because a flush orders INSERTs before UPDATEs and
            // the partial unique index (one PENDING per team+email) would
            // reject the new row.
            $pending->markExpired();
            $this->entityManager->flush();
        }

        $rawToken = bin2hex(random_bytes(32));
        $invitation = new TeamInvitation(
            $team,
            $email,
            $firstName,
            $lastName,
            $inviter,
            TeamInvitation::hashToken($rawToken),
            $now->modify(\sprintf('+%d hours', $this->ttlHours)),
        );
        $this->entityManager->persist($invitation);

        try {
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException) {
            // Two simultaneous invitations for the same (team, email): the
            // database's partial unique index let exactly one through, and
            // this one is by definition a duplicate of it.
            return new InviteOutcome(InviteStatus::INVITATION_ALREADY_PENDING);
        }

        $emailSent = $this->mailer->sendInvitation($invitation, $rawToken);

        return new InviteOutcome(InviteStatus::INVITATION_CREATED, invitation: $invitation, emailSent: $emailSent);
    }

    /**
     * @throws InvitationNotUsableException
     */
    private function assertUsable(?TeamInvitation $invitation): TeamInvitation
    {
        if (null === $invitation) {
            throw new InvitationNotUsableException(InvitationNotUsableException::NOT_FOUND);
        }

        $now = $this->clock->now();

        return match ($invitation->effectiveStatusAt($now)) {
            TeamInvitationStatus::PENDING => $invitation,
            TeamInvitationStatus::ACCEPTED => throw new InvitationNotUsableException(InvitationNotUsableException::ALREADY_USED),
            TeamInvitationStatus::REVOKED => throw new InvitationNotUsableException(InvitationNotUsableException::REVOKED),
            TeamInvitationStatus::EXPIRED => throw new InvitationNotUsableException(InvitationNotUsableException::EXPIRED),
        };
    }

    private function today(): \DateTimeImmutable
    {
        return $this->clock->now()->setTime(0, 0);
    }
}
