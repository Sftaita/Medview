<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\RegisterUserRequest;
use App\Entity\User;
use App\Exception\EmailAlreadyUsedException;
use App\Repository\UserRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class UserRegistrationService
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
    }

    /**
     * @throws EmailAlreadyUsedException
     */
    public function register(RegisterUserRequest $request): User
    {
        if (null !== $this->userRepository->findOneByEmail($request->email)) {
            throw new EmailAlreadyUsedException($request->email);
        }

        $user = new User(
            email: $request->email,
            firstName: $request->firstName,
            lastName: $request->lastName,
            passwordHash: '',
        );
        $user->setPasswordHash($this->passwordHasher->hashPassword($user, $request->plainPassword));

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
            throw new EmailAlreadyUsedException($request->email);
        }

        return $user;
    }
}
