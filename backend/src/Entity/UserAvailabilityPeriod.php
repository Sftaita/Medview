<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\UserAvailabilityPeriodRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * One entry of a User's personal calendar (docs/availability.md) — always
 * attached to the User, never to a TeamMember, so it applies transversally
 * to every team that User belongs to (docs/allocation-algorithm.md's
 * future EligibilityService reads this without any Team filter). This is
 * the key difference from TeamMemberNonParticipationPeriod, which is
 * scoped to one team only.
 *
 * $type distinguishes a hard signal (UNAVAILABLE: eligibility = false
 * everywhere) from a soft one (PREFER_DUTY: never touches eligibility) —
 * see UserAvailabilityType. No $reason field on purpose: this lot's
 * calendar deliberately never asks why.
 *
 * Semi-open interval [$startsAt, $endsAt[ stored as absolute instants
 * (TIMESTAMPTZ, mirroring Duty), so partial-day ranges and DST are handled
 * correctly. Unlike TeamMemberParticipationPeriod/FairnessPeriod, this is
 * not an append-only ledger — reschedule() mutates in place, since a
 * personal calendar entry carries no history/audit requirement.
 */
#[ORM\Entity(repositoryClass: UserAvailabilityPeriodRepository::class)]
#[ORM\Table(name: 'user_availability_periods')]
#[ORM\UniqueConstraint(name: 'uniq_user_availability_periods_stable_id', columns: ['stable_id'])]
#[ORM\Index(columns: ['user_id', 'starts_at', 'ends_at'], name: 'idx_user_availability_periods_user_range')]
class UserAvailabilityPeriod
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: 'uuid')]
    private Uuid $stableId;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(type: 'datetimetz_immutable')]
    private \DateTimeImmutable $startsAt;

    #[ORM\Column(type: 'datetimetz_immutable')]
    private \DateTimeImmutable $endsAt;

    #[ORM\Column(length: 20, enumType: UserAvailabilityType::class)]
    private UserAvailabilityType $type;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        User $user,
        UserAvailabilityType $type,
        \DateTimeImmutable $startsAt,
        \DateTimeImmutable $endsAt,
    ) {
        if ($endsAt <= $startsAt) {
            throw new \InvalidArgumentException('endsAt must be strictly after startsAt.');
        }

        $this->stableId = Uuid::v7();
        $this->user = $user;
        $this->type = $type;
        $this->startsAt = $startsAt;
        $this->endsAt = $endsAt;
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

    public function getUser(): User
    {
        return $this->user;
    }

    public function getStartsAt(): \DateTimeImmutable
    {
        return $this->startsAt;
    }

    public function getEndsAt(): \DateTimeImmutable
    {
        return $this->endsAt;
    }

    public function getType(): UserAvailabilityType
    {
        return $this->type;
    }

    /**
     * The only way to change an existing entry's shape — a personal
     * calendar entry is edited in place, not append-only (contrast with
     * TeamMemberParticipationPeriod).
     */
    public function reschedule(UserAvailabilityType $type, \DateTimeImmutable $startsAt, \DateTimeImmutable $endsAt): void
    {
        if ($endsAt <= $startsAt) {
            throw new \InvalidArgumentException('endsAt must be strictly after startsAt.');
        }

        $this->type = $type;
        $this->startsAt = $startsAt;
        $this->endsAt = $endsAt;
        $this->touch();
    }

    /**
     * Per docs/availability.md, two periods of the same type "touching"
     * (one's end equals the other's start) are treated as conflicting, not
     * just strictly overlapping ones — unlike Duty::overlapsWith().
     */
    public function overlapsOrTouches(self $other): bool
    {
        return $this->startsAt <= $other->endsAt && $other->startsAt <= $this->endsAt;
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
