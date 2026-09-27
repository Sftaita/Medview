<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PlanningPublicationDeliveryRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * One recipient of one PlanningPublication (docs/decisions.md D143) and
 * whether the transport accepted the email — the audit of "who was told".
 * Written after the publication itself is committed (an email failure
 * never rolls a publication back), append-only (database trigger).
 */
#[ORM\Entity(repositoryClass: PlanningPublicationDeliveryRepository::class)]
#[ORM\Table(name: 'planning_publication_deliveries')]
#[ORM\UniqueConstraint(name: 'uniq_planning_publication_deliveries_user', columns: ['publication_id', 'user_id'])]
class PlanningPublicationDelivery
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: PlanningPublication::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private PlanningPublication $publication;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private User $user;

    #[ORM\Column]
    private bool $sent;

    #[ORM\Column]
    private \DateTimeImmutable $attemptedAt;

    public function __construct(PlanningPublication $publication, User $user, bool $sent, \DateTimeImmutable $attemptedAt)
    {
        $this->publication = $publication;
        $this->user = $user;
        $this->sent = $sent;
        $this->attemptedAt = $attemptedAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPublication(): PlanningPublication
    {
        return $this->publication;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function isSent(): bool
    {
        return $this->sent;
    }

    public function getAttemptedAt(): \DateTimeImmutable
    {
        return $this->attemptedAt;
    }
}
