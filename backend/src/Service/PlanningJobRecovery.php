<?php

declare(strict_types=1);

namespace App\Service;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Jobs no worker will ever finish (docs/decisions.md D149) become FAILED —
 * never an eternal SOLVING, never a Planning blocked forever by its own
 * "one active job" rule:
 *
 * - RUNNING with a heartbeat older than STALE_AFTER_SECONDS: its worker is
 *   gone (killed, OOM, container restart) without reaching its own
 *   catch/finally. A live worker beats every PlanningJobHeartbeat::INTERVAL_SECONDS,
 *   including during a CP-SAT solve of any length, so a long job that is
 *   still alive is never touched — the criterion is silence, not duration.
 * - QUEUED for more than QUEUED_ABANDONED_AFTER_SECONDS: no worker picked it
 *   up (worker down, message lost). Failed with its own code so the screen
 *   says so and the manager can relaunch.
 *
 * Their Planning's generations left in SOLVING become FAILED too.
 *
 * Run where it is needed rather than on a timer: when the worker starts,
 * before a new job is requested (so a dead job never blocks a relaunch) and
 * whenever a Planning's job state is read (so the screen never shows a
 * dead job as running). `app:planning-jobs:recover` runs it by hand.
 */
final class PlanningJobRecovery
{
    public const STALE_AFTER_SECONDS = 300;
    public const QUEUED_ABANDONED_AFTER_SECONDS = 1800;
    /** Far beyond any web request's lifetime: only a dead synchronous solve can still be SOLVING by then. */
    public const ORPHAN_SOLVING_AFTER_SECONDS = 900;

    public const WORKER_LOST = 'worker_lost';
    public const NEVER_STARTED = 'never_started';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PlanningJobStore $store,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param int|null $planningId restrict to one Planning, or every Planning when null
     *
     * @return int how many jobs were failed
     */
    public function recover(?int $planningId = null): int
    {
        // The database's clock, like every job timestamp (PlanningJobStore).
        $rows = $this->entityManager->getConnection()->fetchAllAssociative(
            "SELECT id, planning_id, status FROM planning_jobs
             WHERE ((status = 'RUNNING' AND heartbeat_at < LOCALTIMESTAMP - make_interval(secs => :stale))
                 OR (status = 'QUEUED' AND created_at < LOCALTIMESTAMP - make_interval(secs => :queued)))"
            .(null !== $planningId ? ' AND planning_id = :planning' : ''),
            ['stale' => self::STALE_AFTER_SECONDS, 'queued' => self::QUEUED_ABANDONED_AFTER_SECONDS] + (null !== $planningId ? ['planning' => $planningId] : []),
        );

        $failed = 0;
        foreach ($rows as $row) {
            $running = 'RUNNING' === $row['status'];
            $code = $running ? self::WORKER_LOST : self::NEVER_STARTED;
            $detail = $running
                ? \sprintf('No heartbeat for more than %d s: the worker stopped without finishing (killed, restarted or crashed).', self::STALE_AFTER_SECONDS)
                : \sprintf('Still queued after %d s: no worker picked it up.', self::QUEUED_ABANDONED_AFTER_SECONDS);

            if ($this->store->fail((int) $row['id'], $code, $detail)) {
                ++$failed;
                $this->store->failSolvingGenerations((int) $row['planning_id'], 'Interrupted: '.$code);
                $this->logger->warning('PlanningJob {id} recovered as FAILED ({code}).', ['id' => $row['id'], 'code' => $code]);
            }
        }

        $this->failOrphanSolvingGenerations($planningId);

        return $failed;
    }

    /**
     * A generation SOLVING for ORPHAN_SOLVING_AFTER_SECONDS with no RUNNING job
     * on its Planning was not started by a worker job: it came from the
     * technical synchronous endpoint (POST /planning-generations/{id}/solve),
     * whose request was cut by the web time limit. Nothing will ever finish
     * it: FAILED, never an eternal SOLVING.
     */
    private function failOrphanSolvingGenerations(?int $planningId): void
    {
        $count = $this->entityManager->getConnection()->executeStatement(
            "UPDATE planning_generations g SET status = 'FAILED', failure_reason = 'Interrupted: a synchronous solve request did not finish.', lock_version = g.lock_version + 1
             WHERE g.status = 'SOLVING'
               AND g.updated_at < LOCALTIMESTAMP - make_interval(secs => :orphan)
               AND NOT EXISTS (
                   SELECT 1 FROM planning_jobs j JOIN planning_lines l ON l.planning_id = j.planning_id
                   WHERE l.planning_period_id = g.planning_period_id AND j.status = 'RUNNING'
               )"
            .(null !== $planningId ? ' AND g.planning_period_id IN (SELECT planning_period_id FROM planning_lines WHERE planning_id = :planning)' : ''),
            ['orphan' => self::ORPHAN_SOLVING_AFTER_SECONDS] + (null !== $planningId ? ['planning' => $planningId] : []),
        );

        if ($count > 0) {
            $this->logger->warning('{count} orphan SOLVING generation(s) recovered as FAILED.', ['count' => $count]);
        }
    }
}
