<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\AvailabilityCollectionRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * "Who has reviewed their availabilities for this slice of the planning?"
 * (docs/availability-collection.md, docs/decisions.md D120). One row per
 * *new* slice of a Planning: the whole range when it is created, then only
 * the added dates each time it is extended — an extension opens a new
 * collection, it never overwrites nor reopens the previous one.
 *
 * This is workflow/completeness data, deliberately separate from
 * UserAvailabilityPeriod (the business truth about who is unavailable when):
 * nothing here ever feeds eligibility or a snapshot.
 *
 * [$startsAt, $endsAt) is a half-open range of calendar dates in the
 * Planning's timezone, like Planning/PlanningPeriod themselves. Two
 * collections of the same Planning never overlap (exclusion constraint,
 * see migrations).
 */
#[ORM\Entity(repositoryClass: AvailabilityCollectionRepository::class)]
#[ORM\Table(name: 'availability_collections')]
#[ORM\UniqueConstraint(name: 'uniq_availability_collections_stable_id', columns: ['stable_id'])]
#[ORM\Index(columns: ['planning_id'], name: 'idx_availability_collections_planning_id')]
class AvailabilityCollection
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

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $startsAt;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $endsAt;

    #[ORM\Column]
    private \DateTimeImmutable $openedAt;

    /** A calendar date in the Planning's timezone; null = no deadline set. */
    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $deadline;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private User $createdBy;

    #[ORM\Column(length: 20, enumType: AvailabilityCollectionStatus::class)]
    private AvailabilityCollectionStatus $status;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $closedAt = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        Planning $planning,
        \DateTimeImmutable $startsAt,
        \DateTimeImmutable $endsAt,
        User $createdBy,
        \DateTimeImmutable $openedAt,
        ?\DateTimeImmutable $deadline = null,
    ) {
        if ($endsAt <= $startsAt) {
            throw new \InvalidArgumentException('endsAt must be strictly after startsAt.');
        }

        $this->stableId = Uuid::v7();
        $this->planning = $planning;
        $this->startsAt = $startsAt;
        $this->endsAt = $endsAt;
        $this->createdBy = $createdBy;
        $this->openedAt = $openedAt;
        $this->deadline = $deadline;
        $this->status = AvailabilityCollectionStatus::OPEN;
        $this->createdAt = $openedAt;
        $this->updatedAt = $openedAt;
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

    public function getStartsAt(): \DateTimeImmutable
    {
        return $this->startsAt;
    }

    public function getEndsAt(): \DateTimeImmutable
    {
        return $this->endsAt;
    }

    /**
     * The absolute instant the window starts: local midnight of $startsAt
     * in the Planning's timezone. Used to compare the window with
     * UserAvailabilityPeriod (TIMESTAMPTZ) rows.
     */
    public function getStartsAtInstant(): \DateTimeImmutable
    {
        return $this->localMidnight($this->startsAt);
    }

    /** Exclusive: local midnight of $endsAt in the Planning's timezone. */
    public function getEndsAtInstant(): \DateTimeImmutable
    {
        return $this->localMidnight($this->endsAt);
    }

    public function getOpenedAt(): \DateTimeImmutable
    {
        return $this->openedAt;
    }

    public function getDeadline(): ?\DateTimeImmutable
    {
        return $this->deadline;
    }

    public function changeDeadline(?\DateTimeImmutable $deadline, \DateTimeImmutable $now): void
    {
        $this->deadline = $deadline;
        $this->updatedAt = $now;
    }

    public function getCreatedBy(): User
    {
        return $this->createdBy;
    }

    public function getStatus(): AvailabilityCollectionStatus
    {
        return $this->status;
    }

    public function isOpen(): bool
    {
        return AvailabilityCollectionStatus::OPEN === $this->status;
    }

    /**
     * Closing twice is a no-op, never an error: the first closedAt is the
     * one that stays.
     */
    public function close(\DateTimeImmutable $now): void
    {
        if (!$this->isOpen()) {
            return;
        }

        $this->status = AvailabilityCollectionStatus::CLOSED;
        $this->closedAt = $now;
        $this->updatedAt = $now;
    }

    public function getClosedAt(): ?\DateTimeImmutable
    {
        return $this->closedAt;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    private function localMidnight(\DateTimeImmutable $date): \DateTimeImmutable
    {
        return new \DateTimeImmutable($date->format('Y-m-d').' 00:00:00', new \DateTimeZone($this->planning->getTimezone()));
    }
}
