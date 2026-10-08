<?php

declare(strict_types=1);

namespace App\Service\Admin;

use App\Entity\PlatformAuditActorKind;
use App\Entity\PlatformAuditEvent;
use App\Entity\PlatformAuditEventType;
use App\Entity\PlatformAuditOutcome;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * The only writer of platform_audit_events (docs/decisions.md D176).
 *
 * record() only persists: the entry is flushed with the action it
 * describes, in the same transaction — an action never exists without its
 * audit entry, nor the other way round. recordDenied() flushes at once:
 * a refused attempt changes nothing else, and must be kept even though the
 * caller then answers with an error.
 */
final class PlatformAuditLogger
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @param array<string, scalar|null> $context short, non-sensitive facts only
     */
    public function record(
        PlatformAuditEventType $type,
        ?User $actor,
        ?User $target,
        array $context = [],
        PlatformAuditOutcome $outcome = PlatformAuditOutcome::SUCCESS,
        ?PlatformAuditActorKind $actorKind = null,
    ): PlatformAuditEvent {
        $event = new PlatformAuditEvent(
            $type,
            $outcome,
            $actorKind ?? (null !== $actor ? PlatformAuditActorKind::USER : PlatformAuditActorKind::SYSTEM),
            $actor,
            $target,
            array_filter($context, static fn (mixed $value): bool => null !== $value && '' !== $value),
            $this->clock->now(),
        );
        $this->entityManager->persist($event);

        return $event;
    }

    /**
     * @param array<string, scalar|null> $context
     */
    public function recordDenied(PlatformAuditEventType $type, User $actor, ?User $target, string $reason, array $context = []): void
    {
        $this->record($type, $actor, $target, ['denied' => $reason, ...$context], PlatformAuditOutcome::DENIED);
        $this->entityManager->flush();
    }
}
