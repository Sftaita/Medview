<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Dto\RegisterUserRequest;
use App\Exception\AccountExistsForInvitationException;
use App\Exception\EmailAlreadyUsedException;
use App\Repository\UserRepository;
use App\Service\InvitationMailer;
use App\Service\PhoneNumberNormalizer;
use App\Service\TeamInvitationService;
use App\Service\UserRegistrationService;
use Doctrine\DBAL\Driver\Exception as DriverException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Regression test for a UAT finding: two requests registering the same
 * email at the same instant both pass the findOneByEmail() pre-check
 * (neither has committed yet), so the *second* flush() is what actually
 * hits the database's unique constraint. Before this was handled, that
 * threw an uncaught Doctrine\DBAL\Exception\UniqueConstraintViolationException,
 * which surfaced as a 500 with a full debug stack trace on the public
 * /api/register endpoint (see docs/decisions.md). This test simulates the
 * race deterministically — a real concurrent HTTP test isn't reliable in
 * PHPUnit — by making the mocked pre-check report "no conflict" while
 * flush() still throws, exactly like the losing side of a real race would.
 */
final class UserRegistrationServiceTest extends KernelTestCase
{
    public function testConcurrentRegistrationRaceIsConvertedToEmailAlreadyUsedException(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('flush')->willThrowException($this->uniqueViolation());

        $this->expectException(EmailAlreadyUsedException::class);
        $this->service($entityManager)->register($this->request());
    }

    /**
     * Same race on the invitation path: the INSERT of the User loses to a
     * concurrent sign-up. The exception must surface as the dedicated
     * "account exists, log in" outcome (never a 500) — the transaction is
     * rolled back by wrapInTransaction, so the invitation stays PENDING.
     */
    public function testRaceOnTheInvitationPathIsConvertedToAccountExists(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('wrapInTransaction')->willThrowException($this->uniqueViolation());

        $request = $this->request();
        $request->invitationToken = 'some-token';

        $this->expectException(AccountExistsForInvitationException::class);
        $this->service($entityManager)->register($request);
    }

    private function service(EntityManagerInterface $entityManager): UserRegistrationService
    {
        self::bootKernel();

        $userRepository = $this->createMock(UserRepository::class);
        $userRepository->method('findOneByEmail')->willReturn(null);

        $passwordHasher = $this->createMock(UserPasswordHasherInterface::class);
        $passwordHasher->method('hashPassword')->willReturn('hashed-password');

        return new UserRegistrationService(
            $userRepository,
            $entityManager,
            $passwordHasher,
            new PhoneNumberNormalizer('BE'),
            self::getContainer()->get(TeamInvitationService::class),
            self::getContainer()->get(InvitationMailer::class),
        );
    }

    private function request(): RegisterUserRequest
    {
        $request = new RegisterUserRequest();
        $request->email = 'race@example.com';
        $request->plainPassword = 'correct-horse-battery';
        $request->firstName = 'Race';
        $request->lastName = 'Condition';
        $request->phone = '+32470123456';

        return $request;
    }

    private function uniqueViolation(): UniqueConstraintViolationException
    {
        return new UniqueConstraintViolationException($this->createMock(DriverException::class), null);
    }
}
