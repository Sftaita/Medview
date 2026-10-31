<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\UserAvailabilityEventRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * One change of a personal calendar, kept for ever (docs/decisions.md D215):
 * append-only (a database trigger refuses UPDATE and DELETE), written in the
 * same transaction as the change it describes, and never rebuilt from the
 * current row's createdAt / updatedAt. It survives the deletion of the entry
 * (no foreign key to user_availability_periods: $availabilityStableId names
 * it), so "who declared what, when" stays answerable.
 *
 * $previous* is set for RESCHEDULED and DELETED, $new* for CREATED and
 * RESCHEDULED. $impacts is what the change touched at that moment — the
 * already-generated periods its new days fall in, and the duties then held
 * by the person on those days (AvailabilityImpactService) — the facts behind
 * the warning shown, kept as they were.
 */
#[ORM\Entity(repositoryClass: UserAvailabilityEventRepository::class)]
#[ORM\Table(name: 'user_availability_events')]
#[ORM\UniqueConstraint(name: 'uniq_user_availability_events_stable_id', columns: ['stable_id'])]
#[ORM\Index(columns: ['user_id', 'occurred_at'], name: 'idx_user_availability_events_user')]
#[ORM\Index(columns: ['availability_stable_id'], name: 'idx_user_availability_events_availability')]
#[ORM\Index(columns: ['actor_id'], name: 'idx_user_availability_events_actor')]
class UserAvailabilityEvent
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: 'uuid')]
    private Uuid $stableId;

    /** Whose calendar. */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private User $user;

    /** Who did it; null for the SurgicalHub synchronisation. */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'RESTRICT')]
    private ?User $actor;

    #[ORM\Column(length: 20, enumType: UserAvailabilityEventOrigin::class)]
    private UserAvailabilityEventOrigin $origin;

    #[ORM\Column(length: 20, enumType: UserAvailabilityEventAction::class)]
    private UserAvailabilityEventAction $action;

    #[ORM\Column(type: 'uuid')]
    private Uuid $availabilityStableId;

    #[ORM\Column(length: 20, enumType: UserAvailabilitySource::class)]
    private UserAvailabilitySource $source;

    #[ORM\Column(length: 20, nullable: true, enumType: UserAvailabilityType::class)]
    private ?UserAvailabilityType $previousType;

    #[ORM\Column(type: 'datetimetz_immutable', nullable: true)]
    private ?\DateTimeImmutable $previousStartsAt;

    #[ORM\Column(type: 'datetimetz_immutable', nullable: true)]
    private ?\DateTimeImmutable $previousEndsAt;

    #[ORM\Column(length: 20, nullable: true, enumType: UserAvailabilityType::class)]
    private ?UserAvailabilityType $newType;

    #[ORM\Column(type: 'datetimetz_immutable', nullable: true)]
    private ?\DateTimeImmutable $newStartsAt;

    #[ORM\Column(type: 'datetimetz_immutable', nullable: true)]
    private ?\DateTimeImmutable $newEndsAt;

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $impacts;

    /** UTC, like every other journal. */
    #[ORM\Column]
    private \DateTimeImmutable $occurredAt;

    /**
     * @param array{0: UserAvailabilityType, 1: \DateTimeImmutable, 2: \DateTimeImmutable}|null $previous
     * @param array{0: UserAvailabilityType, 1: \DateTimeImmutable, 2: \DateTimeImmutable}|null $new
     * @param array<string, mixed>|null                                                         $impacts
     */
    public function __construct(
        User $user,
        ?User $actor,
        UserAvailabilityEventOrigin $origin,
        UserAvailabilityEventAction $action,
        Uuid $availabilityStableId,
        UserAvailabilitySource $source,
        ?array $previous,
        ?array $new,
        ?array $impacts,
        \DateTimeImmutable $occurredAt,
    ) {
        if ((UserAvailabilityEventAction::CREATED === $action) !== (null === $previous)
            || (UserAvailabilityEventAction::DELETED === $action) !== (null === $new)) {
            throw new \InvalidArgumentException('A creation has no previous values, a deletion no new ones, a change both.');
        }
        if ((UserAvailabilityEventOrigin::USER === $origin) !== (null !== $actor)) {
            throw new \InvalidArgumentException('A change made by a person names them; the synchronisation names nobody.');
        }

        $this->stableId = Uuid::v7();
        $this->user = $user;
        $this->actor = $actor;
        $this->origin = $origin;
        $this->action = $action;
        $this->availabilityStableId = $availabilityStableId;
        $this->source = $source;
        [$this->previousType, $this->previousStartsAt, $this->previousEndsAt] = $previous ?? [null, null, null];
        [$this->newType, $this->newStartsAt, $this->newEndsAt] = $new ?? [null, null, null];
        $this->impacts = $impacts;
        $this->occurredAt = $occurredAt->setTimezone(new \DateTimeZone('UTC'));
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

    public function getActor(): ?User
    {
        return $this->actor;
    }

    public function getOrigin(): UserAvailabilityEventOrigin
    {
        return $this->origin;
    }

    public function getAction(): UserAvailabilityEventAction
    {
        return $this->action;
    }

    public function getAvailabilityStableId(): Uuid
    {
        return $this->availabilityStableId;
    }

    public function getSource(): UserAvailabilitySource
    {
        return $this->source;
    }

    public function getPreviousType(): ?UserAvailabilityType
    {
        return $this->previousType;
    }

    public function getPreviousStartsAt(): ?\DateTimeImmutable
    {
        return $this->previousStartsAt;
    }

    public function getPreviousEndsAt(): ?\DateTimeImmutable
    {
        return $this->previousEndsAt;
    }

    public function getNewType(): ?UserAvailabilityType
    {
        return $this->newType;
    }

    public function getNewStartsAt(): ?\DateTimeImmutable
    {
        return $this->newStartsAt;
    }

    public function getNewEndsAt(): ?\DateTimeImmutable
    {
        return $this->newEndsAt;
    }

    /** @return array<string, mixed>|null */
    public function getImpacts(): ?array
    {
        return $this->impacts;
    }

    public function getOccurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
