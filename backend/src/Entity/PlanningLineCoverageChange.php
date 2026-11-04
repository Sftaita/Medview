<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * One explicit decision about what a line covers (docs/decisions.md D233),
 * append-only: no setter, and a database trigger refuses UPDATE and DELETE.
 * Who changed the line's coverage start from what to what and which duties
 * that retired and left alone, or who created the reinforcements that became
 * necessary. $impact freezes what the manager confirmed.
 */
#[ORM\Entity]
#[ORM\Table(name: 'planning_line_coverage_changes')]
#[ORM\UniqueConstraint(name: 'uniq_planning_line_coverage_changes_stable_id', columns: ['stable_id'])]
#[ORM\Index(columns: ['planning_id'], name: 'idx_planning_line_coverage_changes_planning_id')]
#[ORM\Index(columns: ['planning_line_id'], name: 'idx_planning_line_coverage_changes_line_id')]
#[ORM\Index(columns: ['author_id'], name: 'idx_planning_line_coverage_changes_author_id')]
class PlanningLineCoverageChange
{
    public const COVERAGE_START = 'COVERAGE_START';
    public const REINFORCEMENTS_CREATED = 'REINFORCEMENTS_CREATED';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: 'uuid')]
    private Uuid $stableId;

    #[ORM\ManyToOne(targetEntity: Planning::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Planning $planning;

    #[ORM\ManyToOne(targetEntity: PlanningLine::class)]
    #[ORM\JoinColumn(name: 'planning_line_id', nullable: false, onDelete: 'RESTRICT')]
    private PlanningLine $line;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private User $author;

    #[ORM\Column(length: 30)]
    private string $kind;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $previousStartsOn;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $newStartsOn;

    /** @var array<string, mixed> */
    #[ORM\Column(type: 'json', options: ['jsonb' => true])]
    private array $impact;

    #[ORM\Column]
    private \DateTimeImmutable $occurredAt;

    /**
     * @param array<string, mixed> $impact
     */
    public function __construct(PlanningLine $line, User $author, string $kind, ?\DateTimeImmutable $previousStartsOn, ?\DateTimeImmutable $newStartsOn, array $impact, \DateTimeImmutable $occurredAt)
    {
        $this->stableId = Uuid::v7();
        $this->planning = $line->getPlanning();
        $this->line = $line;
        $this->author = $author;
        $this->kind = $kind;
        $this->previousStartsOn = $previousStartsOn;
        $this->newStartsOn = $newStartsOn;
        $this->impact = $impact;
        $this->occurredAt = $occurredAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getStableId(): Uuid
    {
        return $this->stableId;
    }

    public function getLine(): PlanningLine
    {
        return $this->line;
    }

    public function getKind(): string
    {
        return $this->kind;
    }

    public function getAuthor(): User
    {
        return $this->author;
    }

    public function getPreviousStartsOn(): ?\DateTimeImmutable
    {
        return $this->previousStartsOn;
    }

    public function getNewStartsOn(): ?\DateTimeImmutable
    {
        return $this->newStartsOn;
    }

    /**
     * @return array<string, mixed>
     */
    public function getImpact(): array
    {
        return $this->impact;
    }
}
