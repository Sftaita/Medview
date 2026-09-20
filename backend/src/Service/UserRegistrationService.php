<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\RegisterUserRequest;
use App\Entity\TeamInvitation;
use App\Entity\User;
use App\Exception\AccountExistsForInvitationException;
use App\Exception\EmailAlreadyUsedException;
use App\Exception\InvitationEmailMismatchException;
use App\Exception\InvitationNotUsableException;
use App\Repository\UserRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The only place a User is ever created (docs/decisions.md D111).
 *
 * Two entry paths share one method:
 *  - classic sign-up (no token): create the User, nothing else;
 *  - sign-up through an invitation link (token): in ONE transaction,
 *    validate the invitation, check the email is the invitation's, create
 *    the User, create the memberships of every pending invitation for that
 *    address and mark them ACCEPTED. Any failure rolls all of it back, so
 *    there is never "account without membership" nor "invitation accepted
 *    without account" (docs/decisions.md D113).
 *
 * Deliberately NOT done: a classic sign-up never consumes pending
 * invitations for its email. Emails are not verified yet, so registering
 * with someone else's address must not hand over their team access; only
 * possession of the emailed token proves control of the mailbox.
 */
final class UserRegistrationService
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly PhoneNumberNormalizer $phoneNormalizer,
        private readonly TeamInvitationService $invitationService,
        private readonly InvitationMailer $mailer,
    ) {
    }

    /**
     * @throws EmailAlreadyUsedException           classic sign-up with a taken email
     * @throws InvitationNotUsableException        token unknown/expired/revoked/already used
     * @throws InvitationEmailMismatchException    the email is not the invitation's
     * @throws AccountExistsForInvitationException a User already exists for the invitation's email
     */
    public function register(RegisterUserRequest $request): RegistrationResult
    {
        $email = TeamInvitation::normalizeEmail($request->email);
        $token = null !== $request->invitationToken && '' !== $request->invitationToken ? $request->invitationToken : null;

        $result = null === $token
            ? $this->registerClassic($request, $email)
            : $this->registerFromInvitation($request, $email, $token);

        if ([] !== $result->joined) {
            // One single confirmation email, however many invitations were
            // consumed. Sent after the commit; a transport failure is logged
            // by the mailer and never undoes the registration.
            $this->mailer->sendWelcome($result->user, $result->joined);
        }

        return $result;
    }

    private function registerClassic(RegisterUserRequest $request, string $email): RegistrationResult
    {
        if (null !== $this->userRepository->findOneByEmail($email)) {
            throw new EmailAlreadyUsedException($email);
        }

        $user = $this->buildUser($request, $email);
        $this->entityManager->persist($user);

        try {
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException) {
            // The findOneByEmail() check above is not atomic with this
            // flush: two requests for the same email can both pass it
            // before either commits (e.g. a double-submitted registration
            // form). The database's unique index is the real guarantee;
            // this turns that race into the same clean 409 a sequential
            // duplicate gets, instead of leaking an uncaught DBAL
            // exception as a 500.
            throw new EmailAlreadyUsedException($email);
        }

        return new RegistrationResult($user);
    }

    private function registerFromInvitation(RegisterUserRequest $request, string $email, string $token): RegistrationResult
    {
        try {
            return $this->entityManager->wrapInTransaction(function () use ($request, $email, $token): RegistrationResult {
                // Row-locks the invitation: a second submission of the same
                // link waits here until the first commits, then finds it
                // ACCEPTED and is refused — never a second account.
                $invitation = $this->invitationService->resolveUsableForUpdate($token);

                if ($invitation->getEmail() !== $email) {
                    throw new InvitationEmailMismatchException();
                }

                if (null !== $this->userRepository->findOneByEmail($email)) {
                    throw new AccountExistsForInvitationException();
                }

                $user = $this->buildUser($request, $email);
                $this->entityManager->persist($user);
                $this->entityManager->flush();

                return new RegistrationResult($user, $this->invitationService->consumePendingFor($user));
            });
        } catch (UniqueConstraintViolationException) {
            // A classic sign-up (or another request) took this email between
            // the check above and the INSERT. The whole transaction is
            // rolled back — invitation still PENDING, no half-account — and
            // the invitee is told to log in instead.
            throw new AccountExistsForInvitationException();
        }
    }

    private function buildUser(RegisterUserRequest $request, string $email): User
    {
        $phone = $this->phoneNormalizer->toE164($request->phone);
        if (null === $phone) {
            // Validation (ValidPhoneNumber) normally catches this first;
            // this keeps the service safe when called without it.
            throw new \InvalidArgumentException('Invalid phone number.');
        }

        $user = new User(
            email: $email,
            firstName: trim($request->firstName),
            lastName: trim($request->lastName),
            passwordHash: '',
        );
        $user->setPasswordHash($this->passwordHasher->hashPassword($user, $request->plainPassword));
        $user->setPhoneE164($phone);

        return $user;
    }
}
