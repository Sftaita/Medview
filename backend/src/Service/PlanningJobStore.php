<?php

declare(strict_types=1);

namespace App\Service;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Every PlanningJob transition after its creation (docs/decisions.md D149),
 * each one a single conditional UPDATE: it only applies from the expected
 * status, so a redelivered message, a recovery running concurrently with a
 * worker, or a late worker finishing after being declared lost can never
 * overwrite a state that already moved on. Plain DBAL, never the ORM: it
 * must keep working after an error closed the worker's EntityManager.
 *
 * Every timestamp comes from the database's clock (LOCALTIMESTAMP), never
 * PHP's: the API and the worker run in different containers, and staleness
 * (PlanningJobRecovery) is only meaningful against one single clock.
 */
final class PlanningJobStore
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /** QUEUED → RUNNING. False when another worker already claimed it, or it was failed meanwhile. */
    public function claim(int $jobId): bool
    {
        return 1 === $this->connection()->executeStatement(
            "UPDATE planning_jobs SET status = 'RUNNING', started_at = LOCALTIMESTAMP(0), heartbeat_at = LOCALTIMESTAMP(0) WHERE id = :id AND status = 'QUEUED'",
            ['id' => $jobId],
        );
    }

    /** "Still working": only while RUNNING. */
    public function heartbeat(int $jobId): void
    {
        $this->connection()->executeStatement(
            "UPDATE planning_jobs SET heartbeat_at = LOCALTIMESTAMP(0) WHERE id = :id AND status = 'RUNNING'",
            ['id' => $jobId],
        );
    }

    /**
     * RUNNING → SUCCEEDED. False when the job was declared lost meanwhile
     * (PlanningJobRecovery): the recorded FAILED stays — the engine's own
     * writes, already committed, are still visible in the calendar.
     *
     * @param array<string, mixed> $outcome
     */
    public function succeed(int $jobId, array $outcome): bool
    {
        return 1 === $this->connection()->executeStatement(
            "UPDATE planning_jobs SET status = 'SUCCEEDED', outcome = :outcome, finished_at = LOCALTIMESTAMP(0) WHERE id = :id AND status = 'RUNNING'",
            ['outcome' => json_encode($outcome, \JSON_THROW_ON_ERROR), 'id' => $jobId],
        );
    }

    /**
     * QUEUED/RUNNING → FAILED, with a user-safe code, an internal detail and,
     * when the engine did run, its per-line outcome (what failed where).
     *
     * @param array<string, mixed>|null $outcome
     */
    public function fail(int $jobId, string $code, ?string $detail, ?array $outcome = null): bool
    {
        return 1 === $this->connection()->executeStatement(
            "UPDATE planning_jobs SET status = 'FAILED', failure_code = :code, failure_detail = :detail, outcome = :outcome, finished_at = LOCALTIMESTAMP(0) WHERE id = :id AND status IN ('QUEUED', 'RUNNING')",
            [
                'code' => $code,
                'detail' => null !== $detail ? mb_substr($detail, 0, 4000) : null,
                'outcome' => null !== $outcome ? json_encode($outcome, \JSON_THROW_ON_ERROR) : null,
                'id' => $jobId,
            ],
        );
    }

    /**
     * A generation of this Planning left in SOLVING by an interrupted job
     * (the only kind of job that ever puts one there — at most one job runs
     * per Planning) becomes FAILED: never an eternal SOLVING. The optimistic
     * lock version is bumped like any other write of that row.
     */
    public function failSolvingGenerations(int $planningId, string $reason): int
    {
        return $this->connection()->executeStatement(
            "UPDATE planning_generations SET status = 'FAILED', failure_reason = :reason, lock_version = lock_version + 1
             WHERE status = 'SOLVING' AND planning_period_id IN (SELECT planning_period_id FROM planning_lines WHERE planning_id = :planning)",
            ['reason' => $reason, 'planning' => $planningId],
        );
    }

    private function connection(): Connection
    {
        return $this->entityManager->getConnection();
    }
}
