<?php

declare(strict_types=1);

namespace App\Service\Admin;

use Doctrine\DBAL\Connection;
use Doctrine\Migrations\DependencyFactory;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Read-only technical supervision of MedVue (docs/admin.md §7,
 * docs/decisions.md D177). Every check is performed for real when the page
 * is requested, on MedVue's own resources only — its database, its job
 * queue, the status files its own backup scripts write — and reports one of:
 *
 *  - ok       verified just now;
 *  - warning  verified, and something needs attention;
 *  - error    verified, and failing;
 *  - unknown  could not be verified — never presented as "ok".
 *
 * Deliberately absent: Docker, the host, other applications of the shared
 * server, environment variables, secrets, and any action (no backup, no
 * restore, no command). If PostgreSQL is down, this page is unreachable
 * anyway (authentication needs the database): the public /api/health stays
 * the external probe for that case.
 */
final class SystemHealth
{
    /** A queued job untouched for this long means nothing is consuming the queue. */
    public const QUEUE_STALE_AFTER_MINUTES = 10;

    public function __construct(
        private readonly Connection $connection,
        #[Autowire(service: 'doctrine.migrations.dependency_factory')]
        private readonly DependencyFactory $migrations,
        private readonly BackupStatusReader $backups,
        private readonly AppVersion $version,
        private readonly TechnicalErrorLog $errors,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function report(): array
    {
        $now = $this->clock->now();
        $database = $this->database();
        $databaseUp = 'error' !== $database['status'];

        $checks = [
            'api' => [
                'status' => 'ok',
                'detail' => 'L\'API a répondu à cette requête.',
                'environment' => $this->version->environment(),
                'phpVersion' => \PHP_MAJOR_VERSION.'.'.\PHP_MINOR_VERSION,
            ],
            'database' => $database,
            'migrations' => $databaseUp ? $this->migrationsCheck() : $this->unknown('Base de données injoignable.'),
            'jobQueue' => $databaseUp ? $this->jobQueue() : $this->unknown('Base de données injoignable.'),
            'emails' => $databaseUp ? $this->emails() : $this->unknown('Base de données injoignable.'),
            'backup' => $this->backups->backup(),
            'restoreTest' => $this->backups->restoreTest(),
        ];

        $errors = $databaseUp ? $this->errors->summary() : null;
        $errorsStatus = null === $errors ? 'unknown' : ($errors['total24h'] > 0 ? 'warning' : 'ok');

        return [
            'checkedAt' => AdminFormat::iso($now),
            'overall' => $this->worst([...array_column($checks, 'status'), $errorsStatus]),
            'version' => [
                'release' => $this->version->version(),
                'environment' => $this->version->environment(),
            ],
            'checks' => $checks,
            'errors' => null === $errors ? $this->unknown('Base de données injoignable.') : [
                'status' => $errorsStatus,
                'detail' => 0 === $errors['total24h']
                    ? 'Aucune erreur serveur enregistrée ces dernières 24 heures.'
                    : \sprintf('%d erreur(s) serveur ces dernières 24 heures.', $errors['total24h']),
                ...$errors,
                'retentionDays' => TechnicalErrorLog::RETENTION_DAYS,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function database(): array
    {
        try {
            $start = hrtime(true);
            $this->connection->executeQuery('SELECT 1');
            $latencyMs = round((hrtime(true) - $start) / 1e6, 1);

            $info = $this->connection->fetchAssociative(
                "SELECT current_setting('server_version') AS version, pg_database_size(current_database()) AS size",
            ) ?: [];

            return [
                'status' => 'ok',
                'detail' => 'Connexion PostgreSQL vérifiée.',
                'latencyMs' => $latencyMs,
                'serverVersion' => isset($info['version']) ? (string) $info['version'] : null,
                'sizeBytes' => isset($info['size']) ? (int) $info['size'] : null,
            ];
        } catch (\Throwable) {
            return ['status' => 'error', 'detail' => 'PostgreSQL ne répond pas.', 'latencyMs' => null, 'serverVersion' => null, 'sizeBytes' => null];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function migrationsCheck(): array
    {
        try {
            $calculator = $this->migrations->getMigrationStatusCalculator();
            $pending = \count($calculator->getNewMigrations());
            $unknown = \count($calculator->getExecutedUnavailableMigrations());
            $current = $this->migrations->getVersionAliasResolver()->resolveVersionAlias('current');

            return [
                'status' => 0 === $pending && 0 === $unknown ? 'ok' : 'warning',
                'detail' => match (true) {
                    $pending > 0 => \sprintf('%d migration(s) non appliquée(s).', $pending),
                    $unknown > 0 => \sprintf('%d migration(s) appliquée(s) absente(s) du code déployé.', $unknown),
                    default => 'Schéma à jour.',
                },
                'pending' => $pending,
                'current' => (string) $current,
            ];
        } catch (\Throwable) {
            return $this->unknown('État des migrations illisible.');
        }
    }

    /**
     * Planning calculations go through the Messenger queue (D149). Whether a
     * worker is alive can only be seen when there is work: an empty queue is
     * "ok" for the queue, without claiming anything about an idle worker.
     *
     * @return array<string, mixed>
     */
    private function jobQueue(): array
    {
        try {
            $now = $this->clock->now();
            $queue = $this->connection->fetchAssociative(
                <<<'SQL'
                    SELECT COUNT(*) FILTER (WHERE queue_name = 'planning_jobs') AS waiting,
                           MIN(available_at) FILTER (WHERE queue_name = 'planning_jobs' AND delivered_at IS NULL) AS oldest,
                           COUNT(*) FILTER (WHERE queue_name = 'failed') AS failed
                    FROM messenger_messages
                SQL,
            ) ?: [];
            $jobs = $this->connection->fetchAssociative(
                <<<'SQL'
                    SELECT COUNT(*) FILTER (WHERE status = 'SUCCEEDED') AS succeeded,
                           COUNT(*) FILTER (WHERE status = 'FAILED') AS failed,
                           AVG(EXTRACT(EPOCH FROM (finished_at - started_at))) FILTER (WHERE status = 'SUCCEEDED' AND started_at IS NOT NULL) AS avg_seconds,
                           MAX(EXTRACT(EPOCH FROM (finished_at - started_at))) FILTER (WHERE status = 'SUCCEEDED' AND started_at IS NOT NULL) AS max_seconds
                    FROM planning_jobs WHERE created_at >= :since
                SQL,
                ['since' => PlatformTime::utc($now->modify('-7 days'))],
            ) ?: [];

            $oldest = null !== ($queue['oldest'] ?? null) ? new \DateTimeImmutable((string) $queue['oldest'], new \DateTimeZone('UTC')) : null;
            $stale = null !== $oldest && $oldest < $now->modify(\sprintf('-%d minutes', self::QUEUE_STALE_AFTER_MINUTES));
            $failedMessages = (int) ($queue['failed'] ?? 0);

            return [
                'status' => $stale ? 'error' : ($failedMessages > 0 ? 'warning' : 'ok'),
                'detail' => match (true) {
                    $stale => \sprintf('Un calcul attend depuis plus de %d minutes : le worker ne consomme plus la file.', self::QUEUE_STALE_AFTER_MINUTES),
                    $failedMessages > 0 => \sprintf('%d message(s) dans la file d\'échec (messenger:failed:show).', $failedMessages),
                    default => 'Aucun calcul bloqué dans la file.',
                },
                'waiting' => (int) ($queue['waiting'] ?? 0),
                'oldestWaitingSince' => AdminFormat::iso($oldest),
                'failedMessages' => $failedMessages,
                'last7Days' => [
                    'succeeded' => (int) ($jobs['succeeded'] ?? 0),
                    'failed' => (int) ($jobs['failed'] ?? 0),
                    'averageSeconds' => null !== ($jobs['avg_seconds'] ?? null) ? round((float) $jobs['avg_seconds'], 1) : null,
                    'maxSeconds' => null !== ($jobs['max_seconds'] ?? null) ? round((float) $jobs['max_seconds'], 1) : null,
                ],
            ];
        } catch (\Throwable) {
            return $this->unknown('File des calculs illisible.');
        }
    }

    /**
     * Publication emails are recorded before they are sent (D172): a FAILED
     * row is a real delivery failure, a PENDING one older than an hour means
     * the retry command is not running.
     *
     * @return array<string, mixed>
     */
    private function emails(): array
    {
        try {
            $row = $this->connection->fetchAssociative(
                <<<'SQL'
                    SELECT COUNT(*) FILTER (WHERE status = 'FAILED') AS failed,
                           COUNT(*) FILTER (WHERE status IN ('PENDING', 'SENDING') AND created_at < :hour) AS stuck,
                           COUNT(*) FILTER (WHERE status = 'SENT' AND sent_at >= :week) AS sent
                    FROM planning_publication_notifications
                SQL,
                [
                    'hour' => PlatformTime::utc($this->clock->now()->modify('-1 hour')),
                    'week' => PlatformTime::utc($this->clock->now()->modify('-7 days')),
                ],
            ) ?: [];
            $failed = (int) ($row['failed'] ?? 0);
            $stuck = (int) ($row['stuck'] ?? 0);

            return [
                'status' => $failed > 0 || $stuck > 0 ? 'warning' : 'ok',
                'detail' => match (true) {
                    $failed > 0 => \sprintf('%d email(s) de publication en échec, en attente de reprise.', $failed),
                    $stuck > 0 => \sprintf('%d email(s) de publication en attente depuis plus d\'une heure.', $stuck),
                    default => 'Aucun email de publication en échec.',
                },
                'failed' => $failed,
                'stuck' => $stuck,
                'sentLast7Days' => (int) ($row['sent'] ?? 0),
            ];
        } catch (\Throwable) {
            return $this->unknown('Suivi des emails illisible.');
        }
    }

    /**
     * @return array{status: string, detail: string}
     */
    private function unknown(string $detail): array
    {
        return ['status' => 'unknown', 'detail' => $detail];
    }

    /**
     * @param list<string> $statuses
     */
    private function worst(array $statuses): string
    {
        foreach (['error', 'warning', 'unknown'] as $level) {
            if (\in_array($level, $statuses, true)) {
                return $level;
            }
        }

        return 'ok';
    }
}
