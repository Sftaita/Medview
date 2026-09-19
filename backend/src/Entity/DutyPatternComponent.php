<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\DutyPatternComponentRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * One day of a DutyPattern: "at dayOffset N from the group's anchor date,
 * a duty of this DutyType is required." A component has no meaning
 * without its pattern — see cascade/orphanRemoval on
 * DutyPattern::$components.
 *
 * $team is a deliberate denormalization: it lets the database enforce,
 * via a composite foreign key (see migrations), that $dutyType always
 * belongs to the same PlanningTeam as $pattern — a cross-table invariant a plain
 * CHECK constraint cannot express. DutyPattern::addComponent() also
 * checks it in PHP for a friendly error before ever reaching the database.
 */
#[ORM\Entity(repositoryClass: DutyPatternComponentRepository::class)]
#[ORM\Table(name: 'duty_pattern_components')]
#[ORM\UniqueConstraint(name: 'uniq_pattern_components_offset', columns: ['pattern_id', 'day_offset'])]
class DutyPatternComponent
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: DutyPattern::class, inversedBy: 'components')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private DutyPattern $pattern;

    #[ORM\ManyToOne(targetEntity: PlanningTeam::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private PlanningTeam $team;

    /**
     * 0-based offset in days from the DutyGroupInstance's anchor date.
     */
    #[ORM\Column(type: 'smallint')]
    private int $dayOffset;

    #[ORM\ManyToOne(targetEntity: DutyType::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private DutyType $dutyType;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(DutyPattern $pattern, int $dayOffset, DutyType $dutyType)
    {
        if ($dayOffset < 0) {
            throw new \InvalidArgumentException('dayOffset cannot be negative.');
        }

        $this->pattern = $pattern;
        $this->team = $pattern->getTeam();
        $this->dayOffset = $dayOffset;
        $this->dutyType = $dutyType;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPattern(): DutyPattern
    {
        return $this->pattern;
    }

    public function getDayOffset(): int
    {
        return $this->dayOffset;
    }

    public function getDutyType(): DutyType
    {
        return $this->dutyType;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
