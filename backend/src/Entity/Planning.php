<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PlanningRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * The user-visible planning aggregate (docs/planning.md) — e.g. "Gardes
 * Orthopédie Octobre 2026". Groups one or more PlanningLine, each owning
 * its own PlanningTeam and running its own mono-team engine underneath
 * (PlanningPeriod → FairnessPeriod / Duties / Rules / Generations,
 * unchanged since Lot 3). A PlanningTeam is never shared between two
 * Plannings, and never referenced by a client when creating a line — it is
 * always created inline by PlanningLineService (docs/decisions.md D079).
 *
 * $creator is the sole manager in v1 (docs/decisions.md D071) — an
 * OWNER/ADMIN of one of this Planning's PlanningTeams gets no management
 * right over the Planning merely from that team role. No
 * collaborator/co-owner concept exists yet.
 */
#[ORM\Entity(repositoryClass: PlanningRepository::class)]
#[ORM\Table(name: 'plannings')]
#[ORM\UniqueConstraint(name: 'uniq_plannings_stable_id', columns: ['stable_id'])]
#[ORM\Index(columns: ['creator_id'], name: 'idx_plannings_creator_id')]
class Planning
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: 'uuid')]
    private Uuid $stableId;

    #[ORM\Column(length: 150)]
    private string $name;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private User $creator;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $startsAt;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $endsAt;

    #[ORM\Column(length: 64)]
    private string $timezone;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        string $name,
        User $creator,
        \DateTimeImmutable $startsAt,
        \DateTimeImmutable $endsAt,
        string $timezone,
    ) {
        if ($endsAt <= $startsAt) {
            throw new \InvalidArgumentException('endsAt must be strictly after startsAt.');
        }

        $this->stableId = Uuid::v7();
        $this->name = $name;
        $this->creator = $creator;
        $this->startsAt = $startsAt;
        $this->endsAt = $endsAt;
        $this->timezone = $timezone;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getStableId(): Uuid
    {
        return $this->stableId;
    }

    public function getName(): string
    {
        return $this->name;
    }

    /**
     * The only field a PATCH may change in v1 — changing $startsAt/$endsAt
     * would require cascading through every PlanningLine's PlanningPeriod
     * (docs/planning.md §5) and re-validating each Team's FairnessPeriod,
     * deliberately not attempted here (docs/decisions.md D077).
     */
    public function rename(string $name): void
    {
        $this->name = $name;
        $this->touch();
    }

    /**
     * Grows the range, never shrinks it (docs/availability-collection.md §5,
     * docs/decisions.md D122): every date already planned stays planned.
     * The caller — PlanningExtensionService — is responsible for cascading
     * the same change to each PlanningLine's periods (D075) in the same
     * transaction.
     */
    public function extendTo(\DateTimeImmutable $startsAt, \DateTimeImmutable $endsAt): void
    {
        if ($startsAt > $this->startsAt || $endsAt < $this->endsAt) {
            throw new \InvalidArgumentException('A planning can only be extended: the new range must contain the current one.');
        }

        $this->startsAt = $startsAt;
        $this->endsAt = $endsAt;
        $this->touch();
    }

    public function getCreator(): User
    {
        return $this->creator;
    }

    public function getStartsAt(): \DateTimeImmutable
    {
        return $this->startsAt;
    }

    public function getEndsAt(): \DateTimeImmutable
    {
        return $this->endsAt;
    }

    public function getTimezone(): string
    {
        return $this->timezone;
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
}
