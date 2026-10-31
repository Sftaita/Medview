<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\AvailabilityCollectionResponseRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * One expected respondent of an AvailabilityCollection. Created when the
 * collection opens (or when the person joins the planning while it is still
 * open), so the roster — and the X/Y counters — stay a historical fact
 * instead of being recomputed from the current membership.
 *
 * Attached to the User, not to a PlanningTeamMember stint: availability is
 * personal, and a member who leaves and rejoins is still one respondent
 * (same reasoning as fairness candidates, docs/decisions.md D082).
 *
 * docs/decisions.md D214: a row only says that the person takes part in the
 * window (a participant, until withdrawn). Nobody confirms anything any more:
 * $acknowledgedAt / $acknowledgementKind keep the confirmations given before
 * D214 as history and are never written again. $lastAvailabilityChangeAt
 * records that the person touched their calendar inside the window.
 */
#[ORM\Entity(repositoryClass: AvailabilityCollectionResponseRepository::class)]
#[ORM\Table(name: 'availability_collection_responses')]
#[ORM\UniqueConstraint(name: 'uniq_availability_collection_responses_stable_id', columns: ['stable_id'])]
#[ORM\UniqueConstraint(name: 'uniq_availability_collection_responses_collection_user', columns: ['collection_id', 'user_id'])]
#[ORM\Index(columns: ['user_id'], name: 'idx_availability_collection_responses_user_id')]
class AvailabilityCollectionResponse
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: 'uuid')]
    private Uuid $stableId;

    #[ORM\ManyToOne(targetEntity: AvailabilityCollection::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private AvailabilityCollection $collection;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private User $user;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $acknowledgedAt = null;

    #[ORM\Column(length: 30, nullable: true, enumType: AvailabilityAcknowledgementKind::class)]
    private ?AvailabilityAcknowledgementKind $acknowledgementKind = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastAvailabilityChangeAt = null;

    /** Set when the person left the planning before answering; cleared if they rejoin while the collection is open. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $withdrawnAt = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(AvailabilityCollection $collection, User $user, \DateTimeImmutable $now)
    {
        $this->stableId = Uuid::v7();
        $this->collection = $collection;
        $this->user = $user;
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

    public function getCollection(): AvailabilityCollection
    {
        return $this->collection;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    /** PARTICIPANT or WITHDRAWN (docs/decisions.md D214). */
    public function getStatus(): AvailabilityResponseStatus
    {
        return null !== $this->withdrawnAt ? AvailabilityResponseStatus::WITHDRAWN : AvailabilityResponseStatus::PARTICIPANT;
    }

    /** docs/decisions.md D214: concerned by the collection — announced to and reminded, never asked to confirm. */
    public function isParticipant(): bool
    {
        return null === $this->withdrawnAt;
    }

    /** A confirmation given before docs/decisions.md D214 — history only. */
    public function isAcknowledged(): bool
    {
        return null !== $this->acknowledgedAt;
    }

    public function getAcknowledgedAt(): ?\DateTimeImmutable
    {
        return $this->acknowledgedAt;
    }

    public function getAcknowledgementKind(): ?AvailabilityAcknowledgementKind
    {
        return $this->acknowledgementKind;
    }

    public function recordAvailabilityChange(\DateTimeImmutable $now): void
    {
        $this->lastAvailabilityChangeAt = $now;
        $this->updatedAt = $now;
    }

    public function getLastAvailabilityChangeAt(): ?\DateTimeImmutable
    {
        return $this->lastAvailabilityChangeAt;
    }

    /** The person left the planning before the window: no longer a participant (a confirmation given before D214 stays recorded). */
    public function withdraw(\DateTimeImmutable $now): void
    {
        if (null !== $this->withdrawnAt) {
            return;
        }

        $this->withdrawnAt = $now;
        $this->updatedAt = $now;
    }

    public function reinstate(\DateTimeImmutable $now): void
    {
        if (null === $this->withdrawnAt) {
            return;
        }

        $this->withdrawnAt = null;
        $this->updatedAt = $now;
    }

    public function getWithdrawnAt(): ?\DateTimeImmutable
    {
        return $this->withdrawnAt;
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
