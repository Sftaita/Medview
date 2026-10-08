<?php

declare(strict_types=1);

namespace App\Service\Admin;

use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * What the infrastructure page knows about backups (docs/admin.md §7,
 * docs/decisions.md D177): only what the backup scripts themselves wrote,
 * read-only. scripts/backup/medvue-backup.sh and medvue-restore-test.sh end
 * by writing a small JSON status (date, result, size) into
 * /home/deploy/backups/medvue/status, mounted read-only into the backend
 * container at var/ops-status (docker-compose.prod.yml). The backend never
 * lists, reads or triggers a backup itself, and never sees the dumps.
 *
 * No file → "unknown" (dev, or a server where the scripts have not run since
 * this was installed) — never "ok" by default.
 */
final class BackupStatusReader
{
    /** The nightly backup runs every 24 h: older than this, something stopped. */
    public const BACKUP_STALE_AFTER_HOURS = 36;
    /** A restore test is run by hand; older than this, it is due again. */
    public const RESTORE_TEST_STALE_AFTER_DAYS = 31;

    public function __construct(
        #[Autowire('%kernel.project_dir%/var/ops-status')]
        private readonly string $statusDirectory,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @return array{status: string, finishedAt: ?string, result: ?string, detail: string, postgresDumpBytes: ?int, latestMigration: ?string}
     */
    public function backup(): array
    {
        $data = $this->read('backup.json');
        if (null === $data) {
            return ['status' => 'unknown', 'finishedAt' => null, 'result' => null, 'detail' => 'Aucune remontée des scripts de sauvegarde sur ce serveur.', 'postgresDumpBytes' => null, 'latestMigration' => null];
        }

        [$finishedAt, $result] = $data;
        $raw = $data[2];
        $status = match (true) {
            'success' !== $result => 'error',
            $finishedAt < $this->clock->now()->modify(\sprintf('-%d hours', self::BACKUP_STALE_AFTER_HOURS)) => 'warning',
            default => 'ok',
        };

        return [
            'status' => $status,
            'finishedAt' => AdminFormat::iso($finishedAt),
            'result' => $result,
            'detail' => match ($status) {
                'error' => 'La dernière sauvegarde a échoué (voir backup.log sur le serveur).',
                'warning' => \sprintf('Aucune sauvegarde réussie depuis plus de %d heures.', self::BACKUP_STALE_AFTER_HOURS),
                default => 'Dernière sauvegarde réussie.',
            },
            'postgresDumpBytes' => isset($raw['postgresDumpBytes']) && \is_int($raw['postgresDumpBytes']) && $raw['postgresDumpBytes'] >= 0 ? $raw['postgresDumpBytes'] : null,
            'latestMigration' => isset($raw['latestMigration']) && \is_string($raw['latestMigration']) && 1 === preg_match('/^[A-Za-z0-9_\\\\]{1,120}$/', $raw['latestMigration']) ? $raw['latestMigration'] : null,
        ];
    }

    /**
     * @return array{status: string, finishedAt: ?string, result: ?string, detail: string}
     */
    public function restoreTest(): array
    {
        $data = $this->read('restore-test.json');
        if (null === $data) {
            return ['status' => 'unknown', 'finishedAt' => null, 'result' => null, 'detail' => 'Aucun test de restauration remonté sur ce serveur.'];
        }

        [$finishedAt, $result] = $data;
        $status = match (true) {
            'success' !== $result => 'error',
            $finishedAt < $this->clock->now()->modify(\sprintf('-%d days', self::RESTORE_TEST_STALE_AFTER_DAYS)) => 'warning',
            default => 'ok',
        };

        return [
            'status' => $status,
            'finishedAt' => AdminFormat::iso($finishedAt),
            'result' => $result,
            'detail' => match ($status) {
                'error' => 'Le dernier test de restauration a échoué.',
                'warning' => \sprintf('Dernier test de restauration il y a plus de %d jours.', self::RESTORE_TEST_STALE_AFTER_DAYS),
                default => 'Dernier test de restauration réussi.',
            },
        ];
    }

    /**
     * @return array{0: \DateTimeImmutable, 1: string, 2: array<string, mixed>}|null
     */
    private function read(string $file): ?array
    {
        $path = $this->statusDirectory.'/'.$file;
        if (!is_file($path) || !is_readable($path) || filesize($path) > 4096) {
            return null;
        }

        try {
            $raw = json_decode((string) file_get_contents($path), true, 4, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (!\is_array($raw) || !\is_string($raw['finishedAt'] ?? null) || !\in_array($raw['result'] ?? null, ['success', 'failure'], true)) {
            return null;
        }

        try {
            $finishedAt = new \DateTimeImmutable($raw['finishedAt']);
        } catch (\Exception) {
            return null;
        }

        return [$finishedAt, $raw['result'], $raw];
    }
}
