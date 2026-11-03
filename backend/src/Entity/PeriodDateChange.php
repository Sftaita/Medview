<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PeriodDateChangeRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * One use of "Modifier les dates" (docs/decisions.md D225), append-only: no
 * setter, and a database trigger refuses UPDATE and DELETE. It says who moved
 * which period from what to what, whether the people concerned were told, and
 * freezes in $impact what the manager confirmed: the periods moved, the duties
 * retired with their holders, the days added.
 *
 * The duties it retired or added point to it (Duty::$retiredByChange,
 * Duty::$addedByChange): "a duty created by an extension" is a recorded fact,
 * never deduced from a date.
 */
#[ORM\Entity(repositoryClass: PeriodDateChangeRepository::class)]
#[ORM\Table(name: 'period_date_changes')]
#[ORM\UniqueConstraint(name: 'uniq_period_date_changes_stable_id', columns: ['stable_id'])]
#[ORM\Index(columns: ['planning_id'], name: 'idx_period_date_changes_planning_id')]
#[ORM\Index(columns: ['planning_cycle_id'], name: 'idx_period_date_changes_cycle_id')]
#[ORM\Index(columns: ['author_id'], name: 'idx_period_date_changes_author_id')]
class PeriodDateChange
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: 'uuid')]
    private Uuid $stableId;

    #[ORM\ManyToOne(targetEntity: Planning::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Planning $planning;

    #[ORM\ManyToOne(targetEntity: PlanningCycle::class)]
    #[ORM\JoinColumn(name: 'planning_cycle_id', nullable: false, onDelete: 'RESTRICT')]
    private PlanningCycle $cycle;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private User $author;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $previousStartsAt;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $previousEndsAt;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $newStartsAt;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $newEndsAt;

    #[ORM\Column]
    private bool $notified;

    /** @var array<string, mixed> */
    #[ORM\Column(type: 'json', options: ['jsonb' => true])]
    private array $impact;

    #[ORM\Column]
    private \DateTimeImmutable $occurredAt;

    /**
     * @param array<string, mixed> $impact
     */
    public function __construct(
        Planning $planning,
        PlanningCycle $cycle,
        User $author,
        \DateTimeImmutable $previousStartsAt,
        \DateTimeImmutable $previousEndsAt,
        \DateTimeImmutable $newStartsAt,
        \DateTimeImmutable $newEndsAt,
        bool $notified,
        array $impact,
        \DateTimeImmutable $occurredAt,
    ) {
        $this->stableId = Uuid::v7();
        $this->planning = $planning;
        $this->cycle = $cycle;
        $this->author = $author;
        $this->previousStartsAt = $previousStartsAt;
        $this->previousEndsAt = $previousEndsAt;
        $this->newStartsAt = $newStartsAt;
        $this->newEndsAt = $newEndsAt;
        $this->notified = $notified;
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

    public function getCycle(): PlanningCycle
    {
        return $this->cycle;
    }

    public function getAuthor(): User
    {
        return $this->author;
    }

    public function wasNotified(): bool
    {
        return $this->notified;
    }

    /**
     * @return array<string, mixed>
     */
    public function getImpact(): array
    {
        return $this->impact;
    }
}
