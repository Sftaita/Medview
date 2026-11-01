<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PeriodPreparationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * What the manager of a period planned ahead (docs/decisions.md D210):
 *
 * - the announcement — the day the collection of availabilities opens by
 *   itself and the invitations go out (08:00 Europe/Brussels);
 * - the target date of the generation, which only organises the work and
 *   places the reminders: the generation itself always stays manual, and
 *   nothing closes the collection on that date.
 *
 * The reminders are PeriodPreparationReminder rows; what was really sent is
 * in PeriodPreparationDelivery. One row per period, created the first time
 * the manager saves the panel: creating a period never plans anything.
 */
#[ORM\Entity(repositoryClass: PeriodPreparationRepository::class)]
#[ORM\Table(name: 'period_preparations')]
#[ORM\UniqueConstraint(name: 'uniq_period_preparations_stable_id', columns: ['stable_id'])]
#[ORM\UniqueConstraint(name: 'uniq_period_preparations_cycle', columns: ['planning_cycle_id'])]
#[ORM\Index(columns: ['planning_id'], name: 'idx_period_preparations_planning_id')]
#[ORM\Index(columns: ['updated_by_id'], name: 'idx_period_preparations_updated_by_id')]
class PeriodPreparation
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

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $announceOn = null;

    /** $announceOn at 08:00 Europe/Brussels, in UTC. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $announceAt = null;

    #[ORM\Column(length: 12, nullable: true, enumType: PreparationActionState::class)]
    private ?PreparationActionState $announcementState = null;

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $announcementOutcome = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $announcementExecutedAt = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $targetGenerationOn = null;

    /**
     * docs/decisions.md D214: the indicative date by which the participants
     * are asked to have updated their calendar. Informative only — nothing
     * refuses an edit or a generation once it is past. Given to the period's
     * open collections, and to those the announcement opens.
     */
    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $declarationDeadlineOn = null;

    /** The last manager who saved it — the one on whose behalf the collection is opened. */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private User $updatedBy;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(PlanningCycle $cycle, User $by, \DateTimeImmutable $now)
    {
        $this->stableId = Uuid::v7();
        $this->planning = $cycle->getPlanning();
        $this->cycle = $cycle;
        $this->updatedBy = $by;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getStableId(): Uuid
    {
        return $this->stableId;
    }

    public function getPlanning(): Planning
    {
        return $this->planning;
    }

    public function getCycle(): PlanningCycle
    {
        return $this->cycle;
    }

    public function getAnnounceOn(): ?\DateTimeImmutable
    {
        return $this->announceOn;
    }

    public function getAnnounceAt(): ?\DateTimeImmutable
    {
        return $this->announceAt;
    }

    public function getAnnouncementState(): ?PreparationActionState
    {
        return $this->announcementState;
    }

    public function getAnnouncementOutcome(): ?string
    {
        return $this->announcementOutcome;
    }

    public function getAnnouncementExecutedAt(): ?\DateTimeImmutable
    {
        return $this->announcementExecutedAt;
    }

    public function isAnnouncementScheduled(): bool
    {
        return PreparationActionState::SCHEDULED === $this->announcementState;
    }

    /** Plans (or moves, or with null cancels) an announcement that has not run yet. */
    public function scheduleAnnouncement(?\DateTimeImmutable $on, ?\DateTimeImmutable $at): void
    {
        if (null !== $this->announcementState && !$this->isAnnouncementScheduled()) {
            throw new \LogicException('An announcement that already ran never changes.');
        }
        $this->announceOn = $on;
        $this->announceAt = $at;
        $this->announcementState = null === $on ? null : PreparationActionState::SCHEDULED;
    }

    public function finishAnnouncement(PreparationActionState $state, string $outcome, \DateTimeImmutable $now): void
    {
        if (!$this->isAnnouncementScheduled() || PreparationActionState::SCHEDULED === $state) {
            throw new \LogicException('Only a scheduled announcement finishes, once.');
        }
        $this->announcementState = $state;
        $this->announcementOutcome = $outcome;
        $this->announcementExecutedAt = $now;
    }

    public function getTargetGenerationOn(): ?\DateTimeImmutable
    {
        return $this->targetGenerationOn;
    }

    public function changeTarget(?\DateTimeImmutable $on): void
    {
        $this->targetGenerationOn = $on;
    }

    public function getDeclarationDeadlineOn(): ?\DateTimeImmutable
    {
        return $this->declarationDeadlineOn;
    }

    public function changeDeclarationDeadline(?\DateTimeImmutable $on): void
    {
        $this->declarationDeadlineOn = $on;
    }

    public function getUpdatedBy(): User
    {
        return $this->updatedBy;
    }

    public function touch(User $by, \DateTimeImmutable $now): void
    {
        $this->updatedBy = $by;
        $this->updatedAt = $now;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
