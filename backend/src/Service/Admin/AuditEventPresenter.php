<?php

declare(strict_types=1);

namespace App\Service\Admin;

use App\Entity\PlatformAuditEvent;
use App\Entity\User;

/**
 * JSON shape of an audit entry: who (name and email, never more), on whom,
 * what, when, with what outcome, and the stored non-sensitive context.
 */
final class AuditEventPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function present(PlatformAuditEvent $event): array
    {
        return [
            'stableId' => (string) $event->getStableId(),
            'occurredAt' => AdminFormat::iso($event->getOccurredAt()),
            'type' => $event->getType()->value,
            'outcome' => $event->getOutcome()->value,
            'actorKind' => $event->getActorKind()->value,
            'actor' => self::person($event->getActor()),
            'target' => self::person($event->getTargetUser()),
            'context' => $event->getContext(),
        ];
    }

    /**
     * @return array{stableId: string, firstName: string, lastName: string, email: string}|null
     */
    private static function person(?User $user): ?array
    {
        if (null === $user) {
            return null;
        }

        return [
            'stableId' => (string) $user->getStableId(),
            'firstName' => $user->getFirstName(),
            'lastName' => $user->getLastName(),
            'email' => $user->getEmail(),
        ];
    }
}
