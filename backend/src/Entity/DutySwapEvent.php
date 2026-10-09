<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\DutySwapEventRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * One step of a swap workflow, append-only (docs/duty-swaps.md §7) — same
 * pattern as DutyAssignmentEvent (D131): no setter, no deletion path, and a
 * database trigger refuses UPDATE and DELETE. The history a participant or
 * a manager reads is these rows, in order — never rebuilt from the current
 * status of the requests and proposals.
 *
 * $actor is null for a system transition (expiry, a duty that changed
 * hands meanwhile). $data carries what the audit needs that the references
 * alone do not say — e.g. the recipients notified, the reason a validation
 * failed, the DutyAssignment rows a completed swap superseded and created.
 */
#[ORM\Entity(repositoryClass: DutySwapEventRepository::class)]
#[ORM\Table(name: 'duty_swap_events')]
#[ORM\UniqueConstraint(name: 'uniq_duty_swap_events_stable_id', columns: ['stable_id'])]
#[ORM\Index(columns: ['request_id', 'occurred_at'], name: 'idx_duty_swap_events_request')]
class DutySwapEvent
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: 'uuid')]
    private Uuid $stableId;

    #[ORM\ManyToOne(targetEntity: DutySwapRequest::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private DutySwapRequest $request;

    #[ORM\ManyToOne(targetEntity: DutySwapProposal::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'RESTRICT')]
    private ?DutySwapProposal $proposal;

    #[ORM\Column(length: 30, enumType: DutySwapEventType::class)]
    private DutySwapEventType $type;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'RESTRICT')]
    private ?User $actor;

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON)]
    private array $data;

    #[ORM\Column]
    private \DateTimeImmutable $occurredAt;

    /**
     * @param array<string, mixed> $data
     */
    public function __construct(DutySwapRequest $request, ?DutySwapProposal $proposal, DutySwapEventType $type, ?User $actor, \DateTimeImmutable $occurredAt, array $data = [])
    {
        if (null !== $proposal && $proposal->getRequest() !== $request) {
            throw new \InvalidArgumentException('The proposal of a swap event belongs to its request.');
        }

        $this->stableId = Uuid::v7();
        $this->request = $request;
        $this->proposal = $proposal;
        $this->type = $type;
        $this->actor = $actor;
        $this->occurredAt = $occurredAt;
        $this->data = $data;
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

    public function getProposal(): ?DutySwapProposal
    {
        return $this->proposal;
    }

    public function getType(): DutySwapEventType
    {
        return $this->type;
    }

    public function getActor(): ?User
    {
        return $this->actor;
    }

    /**
     * @return array<string, mixed>
     */
    public function getData(): array
    {
        return $this->data;
    }

    public function getOccurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
