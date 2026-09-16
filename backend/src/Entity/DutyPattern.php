<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\DutyPatternRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * The *definition* of a group of linked duties (e.g. "Friday+Saturday+
 * Sunday", "24 Dec+25 Dec") — not yet a dated instance, see
 * DutyGroupInstance. docs/allocation-algorithm.md §9/§14 requires these
 * groups to become a single atomic unit for the future solver; a pattern
 * with a variable number of components (2 for a weekend, 2 for Dec24+25,
 * potentially more) is naturally a one-to-many relation
 * (DutyPatternComponent) rather than a fixed set of columns or an
 * unqueryable JSON blob — see docs/planning-domain.md for the rationale.
 */
#[ORM\Entity(repositoryClass: DutyPatternRepository::class)]
#[ORM\Table(name: 'duty_patterns')]
#[ORM\UniqueConstraint(name: 'uniq_duty_patterns_stable_id', columns: ['stable_id'])]
#[ORM\UniqueConstraint(name: 'uniq_duty_patterns_team_code', columns: ['team_id', 'code'])]
#[ORM\UniqueConstraint(name: 'uniq_duty_patterns_id_team_id', columns: ['id', 'team_id'])]
class DutyPattern
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: 'uuid')]
    private Uuid $stableId;

    #[ORM\ManyToOne(targetEntity: Team::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Team $team;

    #[ORM\Column(length: 50)]
    private string $code;

    #[ORM\Column(length: 150)]
    private string $name;

    #[ORM\Column]
    private bool $active = true;

    /**
     * @var Collection<int, DutyPatternComponent>
     */
    #[ORM\OneToMany(targetEntity: DutyPatternComponent::class, mappedBy: 'pattern', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['dayOffset' => 'ASC'])]
    private Collection $components;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(Team $team, string $code, string $name)
    {
        $this->stableId = Uuid::v7();
        $this->team = $team;
        $this->code = $code;
        $this->name = $name;
        $this->components = new ArrayCollection();
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

    public function getTeam(): Team
    {
        return $this->team;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): void
    {
        $this->active = $active;
        $this->touch();
    }

    /**
     * @throws \InvalidArgumentException if $dayOffset is already used, or
     *                                   $dutyType belongs to another Team
     */
    public function addComponent(int $dayOffset, DutyType $dutyType): DutyPatternComponent
    {
        if ($dutyType->getTeam() !== $this->team) {
            throw new \InvalidArgumentException('A DutyPatternComponent must reference a DutyType from the same Team as its DutyPattern.');
        }

        foreach ($this->components as $existing) {
            if ($existing->getDayOffset() === $dayOffset) {
                throw new \InvalidArgumentException(sprintf('This pattern already has a component at dayOffset %d.', $dayOffset));
            }
        }

        $component = new DutyPatternComponent($this, $dayOffset, $dutyType);
        $this->components->add($component);
        $this->touch();

        return $component;
    }

    /**
     * @return Collection<int, DutyPatternComponent>
     */
    public function getComponents(): Collection
    {
        return $this->components;
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
