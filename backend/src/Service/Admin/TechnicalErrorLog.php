<?php

declare(strict_types=1);

namespace App\Service\Admin;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * The technical side of supervision (docs/admin.md §7, docs/decisions.md
 * D177) — deliberately not the audit log: one row per unexpected server
 * error (an API 500, a worker message whose handler crashed), holding only
 * when, where (route name, HTTP method) and the exception class. Never the
 * exception message, which can quote user input or data; the full exception
 * stays in the container logs as before.
 *
 * Best-effort: when the database itself is the failure, nothing can be
 * written here and the error only exists in the logs — the infrastructure
 * page says so instead of claiming "no error". Rows are purged after
 * RETENTION_DAYS by `app:platform:purge-telemetry`.
 */
final class TechnicalErrorLog
{
    public const RETENTION_DAYS = 90;

    public function __construct(
        private readonly Connection $connection,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function recordHttp(\Throwable $exception, ?string $route, string $method): void
    {
        $this->insert('HTTP', $exception, $route, $method);
    }

    public function recordWorker(\Throwable $exception, string $messageClass): void
    {
        $this->insert('WORKER', $exception, $messageClass, null);
    }

    /**
     * @return array{total24h: int, total7d: int, latest: list<array{occurredAt: string, source: string, exceptionClass: string, route: ?string, httpMethod: ?string}>}
     */
    public function summary(int $latest = 10): array
    {
        $now = $this->clock->now();
        $counts = $this->connection->fetchAssociative(
            'SELECT COUNT(*) FILTER (WHERE occurred_at >= :day) AS day, COUNT(*) AS week FROM technical_error_events WHERE occurred_at >= :week',
            ['day' => PlatformTime::utc($now->modify('-24 hours')), 'week' => PlatformTime::utc($now->modify('-7 days'))],
        ) ?: ['day' => 0, 'week' => 0];

        return [
            'total24h' => (int) $counts['day'],
            'total7d' => (int) $counts['week'],
            'latest' => $this->latest($latest),
        ];
    }

    /**
     * @return list<array{occurredAt: string, source: string, exceptionClass: string, route: ?string, httpMethod: ?string}>
     */
    public function latest(int $limit, int $offset = 0): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT occurred_at, source, exception_class, route, http_method FROM technical_error_events ORDER BY occurred_at DESC, id DESC LIMIT :limit OFFSET :offset',
            ['limit' => $limit, 'offset' => $offset],
            ['limit' => \Doctrine\DBAL\ParameterType::INTEGER, 'offset' => \Doctrine\DBAL\ParameterType::INTEGER],
        );

        return array_map(static fn (array $row): array => [
            'occurredAt' => AdminFormat::utcToIso((string) $row['occurred_at']),
            'source' => (string) $row['source'],
            'exceptionClass' => (string) $row['exception_class'],
            'route' => null !== $row['route'] ? (string) $row['route'] : null,
            'httpMethod' => null !== $row['http_method'] ? (string) $row['http_method'] : null,
        ], $rows);
    }

    public function count(): int
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM technical_error_events');
    }

    public function purgeOlderThan(\DateTimeImmutable $limit): int
    {
        return (int) $this->connection->executeStatement(
            'DELETE FROM technical_error_events WHERE occurred_at < :limit',
            ['limit' => PlatformTime::utc($limit)],
        );
    }

    private function insert(string $source, \Throwable $exception, ?string $where, ?string $method): void
    {
        try {
            $this->connection->executeStatement(
                'INSERT INTO technical_error_events (occurred_at, source, exception_class, route, http_method) VALUES (:at, :source, :class, :route, :method)',
                [
                    'at' => PlatformTime::utc($this->clock->now()),
                    'source' => $source,
                    'class' => mb_substr($exception::class, 0, 255),
                    'route' => null !== $where ? mb_substr($where, 0, 255) : null,
                    'method' => null !== $method ? mb_substr($method, 0, 10) : null,
                ],
            );
        } catch (\Throwable $failure) {
            $this->logger->warning('Could not record a technical error event.', ['exception' => $failure]);
        }
    }
}
