<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'users')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['user:read'])]
    private ?int $id = null;

    #[ORM\Column(length: 180, unique: true)]
    #[Assert\NotBlank]
    #[Assert\Email]
    #[Groups(['user:read'])]
    private string $email;

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank]
    #[Groups(['user:read'])]
    private string $firstName;

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank]
    #[Groups(['user:read'])]
    private string $lastName;

    /**
     * Hashed password. Never serialize this property.
     */
    #[ORM\Column(length: 255)]
    private string $passwordHash;

    #[ORM\Column]
    #[Groups(['user:read'])]
    private bool $active = true;

    /**
     * Null until the user confirms their address. Reserved for the future
     * email-verification flow (no endpoint sets this yet).
     */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $emailVerifiedAt = null;

    #[ORM\Column]
    #[Groups(['user:read'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    #[Groups(['user:read'])]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $email, string $firstName, string $lastName, string $passwordHash)
    {
        $this->email = $email;
        $this->firstName = $firstName;
        $this->lastName = $lastName;
        $this->passwordHash = $passwordHash;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getFirstName(): string
    {
        return $this->firstName;
    }

    public function setFirstName(string $firstName): void
    {
        $this->firstName = $firstName;
        $this->touch();
    }

    public function getLastName(): string
    {
        return $this->lastName;
    }

    public function setLastName(string $lastName): void
    {
        $this->lastName = $lastName;
        $this->touch();
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    /**
     * Deactivating a user never deletes their row: assignments, audit
     * entries and historical stats keep pointing at this User.
     */
    public function setActive(bool $active): void
    {
        $this->active = $active;
        $this->touch();
    }

    public function getEmailVerifiedAt(): ?\DateTimeImmutable
    {
        return $this->emailVerifiedAt;
    }

    public function markEmailAsVerified(): void
    {
        $this->emailVerifiedAt = new \DateTimeImmutable();
        $this->touch();
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    /**
     * @see PasswordAuthenticatedUserInterface
     */
    public function getPassword(): string
    {
        return $this->passwordHash;
    }

    public function setPasswordHash(string $passwordHash): void
    {
        $this->passwordHash = $passwordHash;
        $this->touch();
    }

    /**
     * @see UserInterface
     */
    public function getUserIdentifier(): string
    {
        return $this->email;
    }

    /**
     * @see UserInterface
     *
     * @return list<string>
     */
    public function getRoles(): array
    {
        // Team-scoped roles (OWNER/ADMIN/MEMBER) are attached via
        // TeamMember once teams exist; every authenticated user gets this
        // baseline role in the meantime.
        return ['ROLE_USER'];
    }

    /**
     * @see UserInterface
     */
    public function eraseCredentials(): void
    {
        // No plaintext/sensitive data is kept on the entity itself.
    }
}
