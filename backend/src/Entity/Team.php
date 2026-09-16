<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\TeamRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A medical team managing its own duty roster. Deliberately holds no
 * reference to its members (see TeamMember) — a User belongs to zero, one
 * or several teams, so the association always lives on the join entity,
 * never as a field on either side pretending there is only one.
 *
 * $stableId (not the auto-increment $id) is the identifier every
 * reproducible calculation, snapshot, tie-break and export must use — see
 * docs/planning-domain.md "Identifiants stables" and
 * docs/allocation-algorithm.md §13-14.
 */
#[ORM\Entity(repositoryClass: TeamRepository::class)]
#[ORM\Table(name: 'teams')]
#[ORM\UniqueConstraint(name: 'uniq_teams_stable_id', columns: ['stable_id'])]
#[ORM\UniqueConstraint(name: 'uniq_teams_slug', columns: ['slug'])]
class Team
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: 'uuid')]
    private Uuid $stableId;

    #[ORM\Column(length: 150)]
    #[Assert\NotBlank]
    private string $name;

    /**
     * URL/human-facing identifier. Unique but not the reproducible
     * calculation key (a rename changes it) — see $stableId.
     */
    #[ORM\Column(length: 150)]
    #[Assert\NotBlank]
    private string $slug;

    /**
     * IANA timezone identifier used to resolve a Duty's wall-clock
     * start/end into an absolute instant (docs/allocation-algorithm.md
     * §Duty / Dates et temps). Not per-user: the team's roster is the unit
     * that shares a single operational timezone in v1.
     */
    #[ORM\Column(length: 64)]
    private string $timezone;

    #[ORM\Column]
    private bool $active = true;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $name, string $slug, string $timezone = 'Europe/Brussels')
    {
        $this->stableId = Uuid::v7();
        $this->name = $name;
        $this->slug = $slug;
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

    public function rename(string $name): void
    {
        $this->name = $name;
        $this->touch();
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function getTimezone(): string
    {
        return $this->timezone;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    /**
     * Deactivating a team never deletes it or its history — see
     * docs/planning-domain.md "Suppression et historique".
     */
    public function setActive(bool $active): void
    {
        $this->active = $active;
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
}
