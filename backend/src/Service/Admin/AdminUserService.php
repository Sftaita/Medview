<?php

declare(strict_types=1);

namespace App\Service\Admin;

use App\Entity\PlatformAuditEventType;
use App\Entity\User;
use App\Exception\AdminActionRefusedException;
use App\Repository\RefreshTokenRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The three account actions of the platform administration
 * (docs/admin.md §3, docs/decisions.md D174): deactivate, reactivate,
 * end every session. Each one is a single transaction carrying its audit
 * entry; a refused attempt is audited as DENIED.
 *
 * Rules:
 *  - never on one's own account (no self-lockout, no self-service through
 *    the admin console);
 *  - a platform administrator cannot be deactivated — the role is revoked
 *    first (Paramètres), so that deactivating can never be used to silence
 *    another administrator without a trace in the role history;
 *  - nothing here touches plannings, teams or assignments: a deactivated
 *    member keeps their history (users are never deleted).
 *
 * Deactivation and "revoke sessions" both bump credentialsVersion: every
 * access token already issued stops working at the next request
 * (JwtCredentialsVersionListener, D142), not only after its 15 minutes —
 * and revoke every refresh-token family, so no device can renew a session.
 */
final class AdminUserService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly RefreshTokenRepository $refreshTokens,
        private readonly PlatformAuditLogger $audit,
    ) {
    }

    public function deactivate(User $actor, User $target, ?string $reason, ?string $ip): void
    {
        $this->refuseSelf(PlatformAuditEventType::USER_DEACTIVATED, $actor, $target, $ip);
        if ($target->isPlatformAdmin()) {
            $this->audit->recordDenied(PlatformAuditEventType::USER_DEACTIVATED, $actor, $target, 'target_is_platform_admin', ['ip' => $ip]);

            throw new AdminActionRefusedException('target_is_platform_admin', 'Retirez d\'abord le rôle d\'administrateur de la plateforme à ce compte.');
        }
        if (!$target->isActive()) {
            throw new AdminActionRefusedException('already_disabled', 'Ce compte est déjà désactivé.');
        }

        $this->entityManager->wrapInTransaction(function () use ($actor, $target, $reason, $ip): void {
            $target->setActive(false);
            $revoked = $this->endSessions($target);
            $this->audit->record(PlatformAuditEventType::USER_DEACTIVATED, $actor, $target, ['reason' => $reason, 'revokedSessions' => $revoked, 'ip' => $ip]);
            $this->entityManager->flush();
        });
    }

    public function reactivate(User $actor, User $target, ?string $reason, ?string $ip): void
    {
        $this->refuseSelf(PlatformAuditEventType::USER_REACTIVATED, $actor, $target, $ip);
        if ($target->isActive()) {
            throw new AdminActionRefusedException('already_active', 'Ce compte est déjà actif.');
        }

        $this->entityManager->wrapInTransaction(function () use ($actor, $target, $reason, $ip): void {
            // No session is restored: the person logs in again.
            $target->setActive(true);
            $this->audit->record(PlatformAuditEventType::USER_REACTIVATED, $actor, $target, ['reason' => $reason, 'ip' => $ip]);
            $this->entityManager->flush();
        });
    }

    /**
     * @return int how many sessions (refresh-token families) were still active
     */
    public function revokeSessions(User $actor, User $target, ?string $reason, ?string $ip): int
    {
        $this->refuseSelf(PlatformAuditEventType::USER_SESSIONS_REVOKED, $actor, $target, $ip);

        return $this->entityManager->wrapInTransaction(function () use ($actor, $target, $reason, $ip): int {
            $revoked = $this->endSessions($target);
            $this->audit->record(PlatformAuditEventType::USER_SESSIONS_REVOKED, $actor, $target, ['reason' => $reason, 'revokedSessions' => $revoked, 'ip' => $ip]);
            $this->entityManager->flush();

            return $revoked;
        });
    }

    private function endSessions(User $target): int
    {
        $families = $this->refreshTokens->countActiveFamilies($target);
        $this->refreshTokens->revokeAllForUser($target);
        $target->bumpCredentialsVersion();

        return $families;
    }

    private function refuseSelf(PlatformAuditEventType $type, User $actor, User $target, ?string $ip): void
    {
        if ($actor === $target) {
            $this->audit->recordDenied($type, $actor, $target, 'cannot_target_self', ['ip' => $ip]);

            throw new AdminActionRefusedException('cannot_target_self', 'Cette action n\'est pas possible sur votre propre compte.');
        }
    }
}
