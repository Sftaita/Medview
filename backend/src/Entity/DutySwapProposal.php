<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\DutySwapProposalRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * One concrete pairing for a swap request (docs/duty-swaps.md §3): the
 * request's offered unit against $counterpartDuty's unit, held by
 * $counterpartMember. Accepting it swaps the two units, both whole.
 *
 * Who decides is derived, never stored: the participant who did NOT author
 * it. For an AGREED request the requester authors the one proposal and the
 * colleague decides; for a SEARCH request a colleague authors one with
 * their own duty and the requester decides. So nobody ever accepts their
 * own proposal, nor decides in somebody else's place.
 *
 * $counterpartAssignment freezes the counterpart's exact current row, like
 * DutySwapRequest::$offeredAssignment — a proposal made on a state that
 * changed since is obsolete, never re-applied to the new state.
 */
#[ORM\Entity(repositoryClass: DutySwapProposalRepository::class)]
#[ORM\Table(name: 'duty_swap_proposals')]
#[ORM\UniqueConstraint(name: 'uniq_duty_swap_proposals_stable_id', columns: ['stable_id'])]
#[ORM\Index(columns: ['status'], name: 'idx_duty_swap_proposals_status')]
class DutySwapProposal
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: 'uuid')]
    private Uuid $stableId;

    #[ORM\ManyToOne(targetEntity: DutySwapRequest::class, inversedBy: 'proposals')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private DutySwapRequest $request;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private User $author;

    #[ORM\ManyToOne(targetEntity: PlanningTeamMember::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private PlanningTeamMember $counterpartMember;

    #[ORM\ManyToOne(targetEntity: Duty::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Duty $counterpartDuty;

    #[ORM\ManyToOne(targetEntity: DutyAssignment::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private DutyAssignment $counterpartAssignment;

    #[ORM\Column(length: 12, enumType: DutySwapProposalStatus::class)]
    private DutySwapProposalStatus $status = DutySwapProposalStatus::PENDING;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $decidedAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'RESTRICT')]
    private ?User $decidedBy = null;

    public function __construct(DutySwapRequest $request, User $author, DutyAssignment $counterpartAssignment, \DateTimeImmutable $createdAt)
    {
        $counterpartMember = $counterpartAssignment->getTeamMember();
        if ($counterpartMember->getPlanningTeam() !== $request->getLine()->getPlanningTeam()) {
            throw new \InvalidArgumentException('A swap is always between two duties of the same line.');
        }
        if ($counterpartMember->getUser() === $request->getRequester()) {
            throw new \InvalidArgumentException('Nobody swaps a duty with themselves.');
        }
        $expectedAuthor = DutySwapKind::AGREED === $request->getKind() ? $request->getRequester() : $counterpartMember->getUser();
        if ($author !== $expectedAuthor) {
            throw new \InvalidArgumentException('An AGREED proposal is authored by the requester, a SEARCH one by the counterpart.');
        }

        $this->stableId = Uuid::v7();
        $this->request = $request;
        $this->author = $author;
        $this->counterpartMember = $counterpartMember;
        $this->counterpartDuty = $counterpartAssignment->getDuty();
        $this->counterpartAssignment = $counterpartAssignment;
        $this->createdAt = $createdAt;
        $request->attachProposal($this);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getStableId(): Uuid
    {
        return $this->stableId;
    }

    public function getRequest(): DutySwapRequest
    {
        return $this->request;
    }

    public function getAuthor(): User
    {
        return $this->author;
    }

    public function getCounterpartMember(): PlanningTeamMember
    {
        return $this->counterpartMember;
    }

    public function getCounterpart(): User
    {
        return $this->counterpartMember->getUser();
    }

    public function getCounterpartDuty(): Duty
    {
        return $this->counterpartDuty;
    }

    public function getCounterpartAssignment(): DutyAssignment
    {
        return $this->counterpartAssignment;
    }

    /** The one participant who may accept or refuse: whoever did not author it. */
    public function getDecider(): User
    {
        return $this->author === $this->request->getRequester() ? $this->getCounterpart() : $this->request->getRequester();
    }

    public function getStatus(): DutySwapProposalStatus
    {
        return $this->status;
    }

    public function isPending(): bool
    {
        return DutySwapProposalStatus::PENDING === $this->status;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getDecidedAt(): ?\DateTimeImmutable
    {
        return $this->decidedAt;
    }

    public function getDecidedBy(): ?User
    {
        return $this->decidedBy;
    }

    public function accept(User $by, \DateTimeImmutable $at): void
    {
        $this->decide(DutySwapProposalStatus::ACCEPTED, $by, $at);
    }

    public function refuse(User $by, \DateTimeImmutable $at): void
    {
        $this->decide(DutySwapProposalStatus::REFUSED, $by, $at);
    }

    public function withdraw(\DateTimeImmutable $at): void
    {
        $this->decide(DutySwapProposalStatus::WITHDRAWN, $this->author, $at);
    }

    /** A system transition (another proposal accepted, request cancelled/expired, state changed): nobody decided it. */
    public function close(DutySwapProposalStatus $status, \DateTimeImmutable $at): void
    {
        if (!\in_array($status, [DutySwapProposalStatus::NOT_SELECTED, DutySwapProposalStatus::CANCELLED, DutySwapProposalStatus::EXPIRED, DutySwapProposalStatus::OBSOLETE], true)) {
            throw new \LogicException('Use accept(), refuse() or withdraw() for a decision.');
        }
        $this->decide($status, null, $at);
    }

    private function decide(DutySwapProposalStatus $status, ?User $by, \DateTimeImmutable $at): void
    {
        if (!$this->isPending()) {
            throw new \LogicException(\sprintf('This proposal is already %s.', $this->status->value));
        }
        $this->status = $status;
        $this->decidedAt = $at;
        $this->decidedBy = $by;
    }
}
