<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\SurgicalHubLinkEventRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Append-only trail of association operations (docs/surgicalhub-integration.md
 * §4.5): who, which account, what, when, with what result. A database trigger
 * refuses UPDATE and DELETE (migration Version20261010090000), as for
 * duty_swap_events. $actorLabel is the SurgicalHub actor's display name when
 * the operation came from there; codes and secrets are never recorded.
 */
#[ORM\Entity(repositoryClass: SurgicalHubLinkEventRepository::class)]
#[ORM\Table(name: 'surgical_hub_link_events')]
#[ORM\Index(columns: ['user_id'], name: 'idx_surgical_hub_link_events_user_id')]
#[ORM\Index(columns: ['link_id'], name: 'idx_surgical_hub_link_events_link_id')]
class SurgicalHubLinkEvent
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private User $user;

    #[ORM\ManyToOne(targetEntity: SurgicalHubLink::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'RESTRICT')]
    private ?SurgicalHubLink $link;

    #[ORM\Column(length: 40, enumType: SurgicalHubLinkEventKind::class)]
    private SurgicalHubLinkEventKind $kind;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $surgicalHubUserId;

    #[ORM\Column(length: 200, nullable: true)]
    private ?string $actorLabel;

    /**
     * Non-secret context, e.g. for STALE_DATA_OVERRIDDEN: the planning, the
     * last successful sync of the person concerned.
     *
     * @var array<string, mixed>|null
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $details;

    #[ORM\Column]
    private \DateTimeImmutable $occurredAt;

    public function __construct(
        User $user,
        SurgicalHubLinkEventKind $kind,
        \DateTimeImmutable $occurredAt,
        ?SurgicalHubLink $link = null,
        ?string $surgicalHubUserId = null,
        ?string $actorLabel = null,
        ?array $details = null,
    ) {
        $this->user = $user;
        $this->kind = $kind;
        $this->occurredAt = $occurredAt;
        $this->link = $link;
        $this->surgicalHubUserId = $surgicalHubUserId;
        $this->actorLabel = $actorLabel;
        $this->details = $details;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getLink(): ?SurgicalHubLink
    {
        return $this->link;
    }

    public function getKind(): SurgicalHubLinkEventKind
    {
        return $this->kind;
    }

    public function getSurgicalHubUserId(): ?string
    {
        return $this->surgicalHubUserId;
    }

    public function getActorLabel(): ?string
    {
        return $this->actorLabel;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getDetails(): ?array
    {
        return $this->details;
    }

    public function getOccurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
