<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Dto\RegisterUserRequest;
use App\Exception\EmailAlreadyUsedException;
use App\Repository\UserRepository;
use App\Service\UserRegistrationService;
use Doctrine\DBAL\Driver\Exception as DriverException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
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
final class UserRegistrationServiceTest extends TestCase
{
    public function testConcurrentRegistrationRaceIsConvertedToEmailAlreadyUsedException(): void
    {
        $request = new RegisterUserRequest();
        $request->email = 'race@example.com';
        $request->plainPassword = 'correct-horse-battery';
        $request->firstName = 'Race';
        $request->lastName = 'Condition';

        $userRepository = $this->createMock(UserRepository::class);
        $userRepository->method('findOneByEmail')->willReturn(null);

        $passwordHasher = $this->createMock(UserPasswordHasherInterface::class);
        $passwordHasher->method('hashPassword')->willReturn('hashed-password');

        $driverException = $this->createMock(DriverException::class);
        $uniqueViolation = new UniqueConstraintViolationException($driverException, null);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('flush')->willThrowException($uniqueViolation);

        $service = new UserRegistrationService($userRepository, $entityManager, $passwordHasher);

        $this->expectException(EmailAlreadyUsedException::class);
        $service->register($request);
    }
}
