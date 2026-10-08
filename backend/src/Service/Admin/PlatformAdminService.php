<?php

declare(strict_types=1);

namespace App\Service\Admin;

use App\Entity\PlatformAuditActorKind;
use App\Entity\PlatformAuditEventType;
use App\Entity\User;
use App\Exception\AdminActionRefusedException;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Grants and revokes the global ROLE_PLATFORM_ADMIN (docs/decisions.md
 * D174) — the only code that calls User::setPlatformAdmin().
 *
 * Two entry points, both audited:
 *  - the server console (`app:platform-admin:grant|revoke`), the only way
 *    to name the first administrator: whoever can run it already operates
 *    the server. Audited with actor kind CONSOLE;
 *  - an existing platform administrator, in Paramètres, who must re-type
 *    their own password (a stolen access token alone cannot create a new
 *    administrator). Never on their own account.
 *
 * There is no request body anywhere that sets this flag: registration and
 * every other endpoint reject unknown fields (D116).
 *
 * Effect on sessions: none needed. Roles are read from the database on each
 * request (the security user provider reloads the User; the JWT's roles
 * claim is never trusted), so a revoked administrator gets 403 on the very
 * next /api/admin request with the token they already hold.
 */
final class PlatformAdminService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserRepository $users,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly PlatformAuditLogger $audit,
    ) {
    }

    public function grantByAdmin(User $actor, string $email, string $password, ?string $ip): User
    {
        $target = $this->users->findOneByEmail($email);
        $this->confirmPassword(PlatformAuditEventType::PLATFORM_ADMIN_GRANTED, $actor, $target, $password, $ip);

        if (null === $target) {
            throw new AdminActionRefusedException('user_not_found', 'Aucun compte MedVue ne correspond à cette adresse.', 404);
        }
        if ($target === $actor) {
            $this->audit->recordDenied(PlatformAuditEventType::PLATFORM_ADMIN_GRANTED, $actor, $target, 'cannot_target_self', ['ip' => $ip]);

            throw new AdminActionRefusedException('cannot_target_self', 'Cette action n\'est pas possible sur votre propre compte.');
        }

        $this->grant($target, $actor, PlatformAuditActorKind::USER, ['ip' => $ip]);

        return $target;
    }

    public function revokeByAdmin(User $actor, User $target, string $password, ?string $ip): void
    {
        $this->confirmPassword(PlatformAuditEventType::PLATFORM_ADMIN_REVOKED, $actor, $target, $password, $ip);

        if ($target === $actor) {
            $this->audit->recordDenied(PlatformAuditEventType::PLATFORM_ADMIN_REVOKED, $actor, $target, 'cannot_target_self', ['ip' => $ip]);

            throw new AdminActionRefusedException('cannot_target_self', 'Vous ne pouvez pas retirer votre propre rôle : demandez-le à un autre administrateur.');
        }

        $this->revoke($target, $actor, PlatformAuditActorKind::USER, ['ip' => $ip]);
    }

    /**
     * @param array<string, scalar|null> $context
     */
    public function grant(User $target, ?User $actor, PlatformAuditActorKind $actorKind, array $context = []): void
    {
        if (!$target->isActive()) {
            throw new AdminActionRefusedException('target_disabled', 'Ce compte est désactivé : réactivez-le avant de lui donner un rôle.');
        }
        if ($target->isPlatformAdmin()) {
            throw new AdminActionRefusedException('already_platform_admin', 'Ce compte est déjà administrateur de la plateforme.');
        }

        $this->entityManager->wrapInTransaction(function () use ($target, $actor, $actorKind, $context): void {
            $target->setPlatformAdmin(true);
            $this->audit->record(PlatformAuditEventType::PLATFORM_ADMIN_GRANTED, $actor, $target, $context, actorKind: $actorKind);
            $this->entityManager->flush();
        });
    }

    /**
     * @param array<string, scalar|null> $context
     */
    public function revoke(User $target, ?User $actor, PlatformAuditActorKind $actorKind, array $context = []): void
    {
        if (!$target->isPlatformAdmin()) {
            throw new AdminActionRefusedException('not_platform_admin', 'Ce compte n\'est pas administrateur de la plateforme.');
        }

        $revoked = $this->entityManager->wrapInTransaction(function () use ($target, $actor, $actorKind, $context): bool {
            // Serialized on the administrators themselves, so two concurrent
            // revocations can never remove the last one between them.
            if (\count($this->users->findPlatformAdminsForUpdate()) <= 1) {
                // Returned, not thrown: an exception would close the EntityManager.
                return false;
            }

            $target->setPlatformAdmin(false);
            $this->audit->record(PlatformAuditEventType::PLATFORM_ADMIN_REVOKED, $actor, $target, $context, actorKind: $actorKind);
            $this->entityManager->flush();

            return true;
        });

        if (!$revoked) {
            throw new AdminActionRefusedException('last_platform_admin', 'Impossible de retirer le dernier administrateur de la plateforme.');
        }
    }

    private function confirmPassword(PlatformAuditEventType $type, User $actor, ?User $target, string $password, ?string $ip): void
    {
        if ('' === $password || !$this->passwordHasher->isPasswordValid($actor, $password)) {
            $this->audit->recordDenied($type, $actor, $target, 'password_confirmation_failed', ['ip' => $ip]);

            throw new AdminActionRefusedException('password_confirmation_failed', 'Mot de passe incorrect.', 403);
        }
    }
}
