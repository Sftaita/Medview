<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Refreshes the running PlanningJob's heartbeat_at (docs/decisions.md D149)
 * at most every INTERVAL_SECONDS, whatever the caller's frequency.
 * PlanningJobRecovery declares a RUNNING job lost only once its heartbeat is
 * older than PlanningJobRecovery::STALE_AFTER_SECONDS — a job that runs for
 * an hour but keeps beating is never touched.
 *
 * Written on the application connection: every long step it covers (the
 * CP-SAT subprocess, snapshot and problem building) runs outside any
 * transaction, so each beat is committed — visible to other processes —
 * immediately. The throttle uses the process's monotonic clock; the stored
 * value is the database's (PlanningJobStore).
 */
final class PlanningJobHeartbeat implements WorkHeartbeat
{
    public const INTERVAL_SECONDS = 10;

    private ?int $jobId = null;
    private ?float $lastBeatAt = null;

    public function __construct(
        private readonly PlanningJobStore $store,
    ) {
    }

    public function start(int $jobId): void
    {
        $this->jobId = $jobId;
        $this->lastBeatAt = null;
    }

    public function stop(): void
    {
        $this->jobId = null;
        $this->lastBeatAt = null;
    }

    public function beat(): void
    {
        if (null === $this->jobId) {
            return;
        }

        $now = hrtime(true) / 1e9;
        if (null !== $this->lastBeatAt && $now - $this->lastBeatAt < self::INTERVAL_SECONDS) {
            return;
        }
        $this->lastBeatAt = $now;
        $this->store->heartbeat($this->jobId);
    }
}
