<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\DutySwapRequestRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * A member asks to swap one duty unit they hold (docs/duty-swaps.md,
 * docs/decisions.md D178). NEVER a source of truth for who holds what: the
 * calendar is DutyAssignment.current alone, and a request — whatever its
 * status — changes nothing until DutySwapService::accept() writes the swap.
 * Until then the requester remains responsible for their duty.
 *
 * $offeredDuty is the unit's representative duty (its first day for a
 * block — the whole DutyGroupInstance is always swapped, never one of its
 * days). $offeredAssignment freezes the exact DutyAssignment row the
 * requester held when asking: if that row is no longer current at
 * acceptance time (a manager reassigned it, another swap took it), the
 * request is obsolete — even if the same person holds the duty again
 * through a newer row, a decision taken on the old state is never applied
 * to the new one.
 *
 * Status transitions are named methods only (never a free setter), each
 * from OPEN to a terminal status. A request is never deleted.
 */
#[ORM\Entity(repositoryClass: DutySwapRequestRepository::class)]
#[ORM\Table(name: 'duty_swap_requests')]
#[ORM\UniqueConstraint(name: 'uniq_duty_swap_requests_stable_id', columns: ['stable_id'])]
#[ORM\Index(columns: ['status'], name: 'idx_duty_swap_requests_status')]
class DutySwapRequest
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

    #[ORM\ManyToOne(targetEntity: PlanningLine::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private PlanningLine $line;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private User $requester;

    #[ORM\ManyToOne(targetEntity: PlanningTeamMember::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private PlanningTeamMember $requesterMember;

    #[ORM\ManyToOne(targetEntity: Duty::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Duty $offeredDuty;

    #[ORM\ManyToOne(targetEntity: DutyAssignment::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private DutyAssignment $offeredAssignment;

    #[ORM\Column(length: 10, enumType: DutySwapKind::class)]
    private DutySwapKind $kind;

    #[ORM\Column(length: 10, enumType: DutySwapAudience::class)]
    private DutySwapAudience $audience;

    #[ORM\Column(length: 10, enumType: DutySwapRequestStatus::class)]
    private DutySwapRequestStatus $status = DutySwapRequestStatus::OPEN;

    /** When the offered unit starts: past it, the request can only expire. */
    #[ORM\Column]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $closedAt = null;

    #[ORM\ManyToOne(targetEntity: DutySwapProposal::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'RESTRICT')]
    private ?DutySwapProposal $acceptedProposal = null;

    /** @var Collection<int, DutySwapRequestRecipient> */
    #[ORM\OneToMany(targetEntity: DutySwapRequestRecipient::class, mappedBy: 'request', cascade: ['persist'])]
    private Collection $recipients;

    /** @var Collection<int, DutySwapProposal> */
    #[ORM\OneToMany(targetEntity: DutySwapProposal::class, mappedBy: 'request')]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $proposals;

    /**
     * @param list<User> $recipients exactly one for AGREED, at least one for SELECTED, none for ALL
     */
    public function __construct(
        PlanningLine $line,
        PlanningTeamMember $requesterMember,
        Duty $offeredDuty,
        DutyAssignment $offeredAssignment,
        DutySwapKind $kind,
        DutySwapAudience $audience,
        array $recipients,
        \DateTimeImmutable $expiresAt,
        \DateTimeImmutable $createdAt,
    ) {
        if ($offeredAssignment->getDuty() !== $offeredDuty || $offeredAssignment->getTeamMember() !== $requesterMember) {
            throw new \InvalidArgumentException("The offered assignment must be the requester's current row of the offered duty.");
        }
        if ($requesterMember->getPlanningTeam() !== $line->getPlanningTeam()) {
            throw new \InvalidArgumentException("The requester must be a member of the request's line.");
        }
        if (DutySwapAudience::ALL === $audience ? [] !== $recipients : [] === $recipients) {
            throw new \InvalidArgumentException('Recipients are listed for SELECTED only, and never empty there.');
        }
        if (DutySwapKind::AGREED === $kind && (DutySwapAudience::SELECTED !== $audience || 1 !== \count($recipients))) {
            throw new \InvalidArgumentException('An AGREED request has exactly one selected recipient.');
        }

        $this->stableId = Uuid::v7();
        $this->planning = $line->getPlanning();
        $this->line = $line;
        $this->requester = $requesterMember->getUser();
        $this->requesterMember = $requesterMember;
        $this->offeredDuty = $offeredDuty;
        $this->offeredAssignment = $offeredAssignment;
        $this->kind = $kind;
        $this->audience = $audience;
        $this->expiresAt = $expiresAt;
        $this->createdAt = $createdAt;
        $this->recipients = new ArrayCollection();
        $this->proposals = new ArrayCollection();
        foreach ($recipients as $recipient) {
            if ($recipient === $this->requester) {
                throw new \InvalidArgumentException('A requester is never their own recipient.');
            }
            $this->recipients->add(new DutySwapRequestRecipient($this, $recipient));
        }
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

    public function getLine(): PlanningLine
    {
        return $this->line;
    }

    public function getRequester(): User
    {
        return $this->requester;
    }

    public function getRequesterMember(): PlanningTeamMember
    {
        return $this->requesterMember;
    }

    public function getOfferedDuty(): Duty
    {
        return $this->offeredDuty;
    }

    public function getOfferedAssignment(): DutyAssignment
    {
        return $this->offeredAssignment;
    }

    public function getKind(): DutySwapKind
    {
        return $this->kind;
    }

    public function getAudience(): DutySwapAudience
    {
        return $this->audience;
    }

    public function getStatus(): DutySwapRequestStatus
    {
        return $this->status;
    }

    public function isOpen(): bool
    {
        return DutySwapRequestStatus::OPEN === $this->status;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getClosedAt(): ?\DateTimeImmutable
    {
        return $this->closedAt;
    }

    public function getAcceptedProposal(): ?DutySwapProposal
    {
        return $this->acceptedProposal;
    }

    /**
     * @return list<User>
     */
    public function getRecipientUsers(): array
    {
        return array_values(array_map(static fn (DutySwapRequestRecipient $r): User => $r->getUser(), $this->recipients->toArray()));
    }

    public function isRecipient(User $user): bool
    {
        foreach ($this->recipients as $recipient) {
            if ($recipient->getUser() === $user) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<DutySwapProposal>
     */
    public function getProposals(): array
    {
        return array_values($this->proposals->toArray());
    }

    /** Called by DutySwapProposal's constructor only, so the in-memory collection stays in sync. */
    public function attachProposal(DutySwapProposal $proposal): void
    {
        if ($proposal->getRequest() !== $this) {
            throw new \LogicException('This proposal belongs to another request.');
        }
        $this->proposals->add($proposal);
    }

    public function complete(DutySwapProposal $accepted, \DateTimeImmutable $at): void
    {
        if ($accepted->getRequest() !== $this) {
            throw new \LogicException('The accepted proposal belongs to another request.');
        }
        $this->close(DutySwapRequestStatus::COMPLETED, $at);
        $this->acceptedProposal = $accepted;
    }

    public function refuse(\DateTimeImmutable $at): void
    {
        if (DutySwapKind::AGREED !== $this->kind) {
            throw new \LogicException('Only an AGREED request is refused as a whole.');
        }
        $this->close(DutySwapRequestStatus::REFUSED, $at);
    }

    public function cancel(\DateTimeImmutable $at): void
    {
        $this->close(DutySwapRequestStatus::CANCELLED, $at);
    }

    public function expire(\DateTimeImmutable $at): void
    {
        $this->close(DutySwapRequestStatus::EXPIRED, $at);
    }

    public function markObsolete(\DateTimeImmutable $at): void
    {
        $this->close(DutySwapRequestStatus::OBSOLETE, $at);
    }

    private function close(DutySwapRequestStatus $status, \DateTimeImmutable $at): void
    {
        if (!$this->isOpen()) {
            throw new \LogicException(\sprintf('This swap request is already %s.', $this->status->value));
        }
        $this->status = $status;
        $this->closedAt = $at;
    }
}
