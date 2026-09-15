<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\RegisterUserRequest;
use App\Entity\User;
use App\Exception\EmailAlreadyUsedException;
use App\Repository\UserRepository;
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
        $this->entityManager->flush();

        return $user;
    }
}
