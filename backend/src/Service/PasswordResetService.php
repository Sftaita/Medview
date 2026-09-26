<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\PasswordResetToken;
use App\Entity\User;
use App\Exception\InvalidPasswordResetTokenException;
use App\Repository\PasswordResetTokenRepository;
use App\Repository\UserRepository;
use App\Security\PasswordResetFailureReason;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * "Forgot password" business logic (docs/decisions.md D141/D142):
 * request → email → confirm → all sessions + all prior JWTs invalidated.
 *
 * Deliberately knows nothing about HTTP: the controller is responsible for
 * the identical public response regardless of what happens here (email
 * unknown, account disabled, ...) — see requestReset()'s doc block. This
 * mirrors TeamInvitationService/RefreshTokenService's separation.
 */
final class PasswordResetService
{
    public function __construct(
        private readonly PasswordResetTokenRepository $tokenRepository,
        private readonly UserRepository $userRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly RefreshTokenService $refreshTokenService,
        private readonly PasswordResetMailer $mailer,
        private readonly LoggerInterface $logger,
        private readonly ClockInterface $clock,
        #[Autowire(env: 'int:PASSWORD_RESET_TOKEN_TTL')]
        private readonly int $ttlSeconds,
    ) {
    }

    /**
     * Always succeeds from the caller's point of view — there is nothing to
     * catch here. Whether the email is unknown, belongs to a deactivated
     * account, or is a real eligible account, this method does the right
     * thing internally and returns silently either way; the controller
     * must send the exact same public response in every case (never call
     * site-specific branching on top of this).
     *
     * When the account is eligible: any reset token still usable for it is
     * superseded (never two live tokens for one user), a fresh one is
     * issued and its link emailed. The lookup, the supersession and the
     * issuance happen inside one row-locked transaction so two near-
     * simultaneous requests for the same address can never both leave a
     * usable token behind.
     */
    public function requestReset(string $email, ?string $requestedByIp): void
    {
        $issued = $this->entityManager->wrapInTransaction(
            function () use ($email, $requestedByIp): ?array {
                $user = $this->userRepository->findOneByEmail($email);

                if (null === $user) {
                    $this->logger->info('Password reset requested for an unknown email.');

                    return null;
                }

                if (!$user->isActive()) {
                    $this->logger->info('Password reset requested for a deactivated account.', ['userId' => $user->getId()]);

                    return null;
                }

                $now = $this->clock->now();

                // Row-locked: any request racing this one for the same user
                // blocks here until this transaction commits or rolls back,
                // so exactly one token ever ends up usable afterwards.
                foreach ($this->tokenRepository->findUsableByUserForUpdate($user, $now) as $stale) {
                    $stale->revoke();
                }

                $rawToken = bin2hex(random_bytes(32));
                $token = new PasswordResetToken(
                    user: $user,
                    tokenHash: PasswordResetToken::hashToken($rawToken),
                    expiresAt: $now->modify("+{$this->ttlSeconds} seconds"),
                    requestedByIp: $requestedByIp,
                );
                $this->entityManager->persist($token);
                $this->entityManager->flush();

                return [$user, $rawToken];
            },
        );

        if (null === $issued) {
            return;
        }

        [$user, $rawToken] = $issued;

        // Sent after the commit, best-effort (PasswordResetMailer never
        // throws): a transport failure must never surface as a different
        // public response than the unknown-email case.
        $this->mailer->sendResetLink($user, $rawToken);
    }

    /**
     * @throws InvalidPasswordResetTokenException token unusable for any reason — the controller must turn every
     *                                            case into the same generic "invalid or expired" response
     */
    public function confirmReset(string $rawToken, string $newPlainPassword): void
    {
        $user = $this->entityManager->wrapInTransaction(function () use ($rawToken, $newPlainPassword): User {
            // Row-locked: a second confirmation of the same raw token
            // blocks here until this transaction commits, then finds the
            // token already consumed.
            $token = $this->tokenRepository->findOneByTokenHashForUpdate(PasswordResetToken::hashToken($rawToken));

            if (null === $token) {
                throw new InvalidPasswordResetTokenException(PasswordResetFailureReason::NOT_FOUND);
            }

            if (null !== $token->getConsumedAt()) {
                throw new InvalidPasswordResetTokenException(PasswordResetFailureReason::CONSUMED);
            }

            if (null !== $token->getRevokedAt()) {
                throw new InvalidPasswordResetTokenException(PasswordResetFailureReason::REVOKED);
            }

            if ($token->getExpiresAt() <= $this->clock->now()) {
                throw new InvalidPasswordResetTokenException(PasswordResetFailureReason::EXPIRED);
            }

            $user = $token->getUser();
            if (!$user->isActive()) {
                throw new InvalidPasswordResetTokenException(PasswordResetFailureReason::ACCOUNT_NOT_ELIGIBLE);
            }

            $user->setPasswordHash($this->passwordHasher->hashPassword($user, $newPlainPassword));
            $user->bumpCredentialsVersion();
            $token->consume();

            // Every OTHER still-usable token for this user becomes moot —
            // this is the "most recent request wins" invariant collapsing
            // to "the account is fresh again" once a reset actually lands.
            // $token itself is deliberately excluded: the query above still
            // sees it as usable (its consume() above is not flushed yet),
            // and revoking an already-consumed entity would violate the
            // "never both" DB constraint on the same row.
            foreach ($this->tokenRepository->findUsableByUserForUpdate($user, $this->clock->now()) as $other) {
                if ($other !== $token) {
                    $other->revoke();
                }
            }

            $this->entityManager->flush();

            // Refresh tokens (all devices/browsers) revoked in the same
            // transaction as the password/version change: never a window
            // where the password changed but an old session still works.
            $this->refreshTokenService->revokeAllForUser($user);

            return $user;
        });

        // Sent after the commit, best-effort, outside the row lock held
        // above (same reasoning as UserRegistrationService's welcome email).
        $this->mailer->sendPasswordChangedAlert($user);
    }
}
