<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PlatformAuditEventRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * One entry of the platform audit log (docs/admin.md §6, docs/decisions.md
 * D176): sensitive administrative and account-security actions. Append-only
 * — no setter, and a database trigger refuses UPDATE and DELETE.
 *
 * Not a technical log (unexpected server errors live in
 * technical_error_events, purged after 90 days), and never a carrier of
 * medical or planning content: $context only holds short, non-sensitive
 * facts (a free-text reason typed by the administrator, the requesting IP,
 * the registration path…). Never a password, a token, or a secret.
 */
#[ORM\Entity(repositoryClass: PlatformAuditEventRepository::class)]
#[ORM\Table(name: 'platform_audit_events')]
#[ORM\UniqueConstraint(name: 'uniq_platform_audit_events_stable_id', columns: ['stable_id'])]
#[ORM\Index(columns: ['occurred_at'], name: 'idx_platform_audit_events_occurred')]
#[ORM\Index(columns: ['type', 'occurred_at'], name: 'idx_platform_audit_events_type')]
#[ORM\Index(columns: ['target_user_id', 'occurred_at'], name: 'idx_platform_audit_events_target')]
class PlatformAuditEvent
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: 'uuid')]
    private Uuid $stableId;

    #[ORM\Column]
    private \DateTimeImmutable $occurredAt;

    #[ORM\Column(length: 40, enumType: PlatformAuditEventType::class)]
    private PlatformAuditEventType $type;

    #[ORM\Column(length: 10, enumType: PlatformAuditOutcome::class)]
    private PlatformAuditOutcome $outcome;

    #[ORM\Column(length: 10, enumType: PlatformAuditActorKind::class)]
    private PlatformAuditActorKind $actorKind;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'RESTRICT')]
    private ?User $actor;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'target_user_id', nullable: true, onDelete: 'RESTRICT')]
    private ?User $targetUser;

    /** @var array<string, scalar|null>|null */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $context;

    /**
     * @param array<string, scalar|null> $context
     */
    public function __construct(
        PlatformAuditEventType $type,
        PlatformAuditOutcome $outcome,
        PlatformAuditActorKind $actorKind,
        ?User $actor,
        ?User $targetUser,
        array $context,
        \DateTimeImmutable $occurredAt,
    ) {
        if ((PlatformAuditActorKind::USER === $actorKind) !== (null !== $actor)) {
            throw new \InvalidArgumentException('A USER actor needs a User, and only a USER actor has one.');
        }

        $this->stableId = Uuid::v7();
        $this->type = $type;
        $this->outcome = $outcome;
        $this->actorKind = $actorKind;
        $this->actor = $actor;
        $this->targetUser = $targetUser;
        $this->context = [] === $context ? null : $context;
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

    public function getOccurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function getType(): PlatformAuditEventType
    {
        return $this->type;
    }

    public function getOutcome(): PlatformAuditOutcome
    {
        return $this->outcome;
    }

    public function getActorKind(): PlatformAuditActorKind
    {
        return $this->actorKind;
    }

    public function getActor(): ?User
    {
        return $this->actor;
    }

    public function getTargetUser(): ?User
    {
        return $this->targetUser;
    }

    /**
     * @return array<string, scalar|null>
     */
    public function getContext(): array
    {
        return $this->context ?? [];
    }
}
