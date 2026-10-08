<?php

declare(strict_types=1);

namespace App\Service\Admin;

use App\Repository\UserRepository;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The "Paramètres" page (docs/admin.md §8): who administers the platform,
 * and the security settings in force — read-only. These values come from
 * the server configuration and are shown so an administrator can check
 * them, never edited from the browser: no endpoint writes configuration or
 * environment variables. Only durations and on/off flags are exposed —
 * never a key, a passphrase, a DSN or a secret.
 */
final class AdminSettings
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly AdminUserDirectory $directory,
        private readonly AppVersion $version,
        #[Autowire(env: 'int:JWT_TOKEN_TTL')]
        private readonly int $accessTokenTtl,
        #[Autowire(env: 'int:REFRESH_TOKEN_TTL')]
        private readonly int $refreshTokenTtl,
        #[Autowire(env: 'int:PASSWORD_RESET_TOKEN_TTL')]
        private readonly int $passwordResetTtl,
        #[Autowire(env: 'int:INVITATION_TTL_HOURS')]
        private readonly int $invitationTtlHours,
        #[Autowire(env: 'bool:COOKIE_SECURE')]
        private readonly bool $cookieSecure,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function view(): array
    {
        $admins = $this->users->findPlatformAdmins();
        $lastActivity = $this->directory->lastActivityOf(array_values(array_filter(array_map(
            static fn ($user): ?int => $user->getId(),
            $admins,
        ))));

        return [
            'platformAdmins' => array_map(static fn ($user): array => [
                'stableId' => (string) $user->getStableId(),
                'email' => $user->getEmail(),
                'firstName' => $user->getFirstName(),
                'lastName' => $user->getLastName(),
                'active' => $user->isActive(),
                'lastActivityAt' => $lastActivity[$user->getId()] ?? null,
            ], $admins),
            'security' => [
                'accessTokenTtlSeconds' => $this->accessTokenTtl,
                'refreshTokenTtlSeconds' => $this->refreshTokenTtl,
                'passwordResetTokenTtlSeconds' => $this->passwordResetTtl,
                'invitationTtlHours' => $this->invitationTtlHours,
                'secureCookies' => $this->cookieSecure,
            ],
            'telemetry' => [
                'timezone' => PlatformTime::TIMEZONE,
                'activityRetentionDays' => TelemetryRetention::ACTIVITY_RETENTION_DAYS,
                'technicalErrorRetentionDays' => TechnicalErrorLog::RETENTION_DAYS,
                'auditRetention' => 'permanent',
            ],
            'version' => [
                'release' => $this->version->version(),
                'environment' => $this->version->environment(),
            ],
        ];
    }
}
