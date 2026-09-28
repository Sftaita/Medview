<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\PlanningGenerationStatus;
use App\Entity\PlanningJob;
use App\Entity\PlanningJobKind;
use App\Entity\RestPolicyOptions;
use App\Exception\NoSolverParameterSetException;
use App\Exception\PlanningCompletionStaleException;
use App\Exception\PlanningGenerationInProgressException;
use App\Exception\PlanningNotLaunchableException;
use App\Fairness\CoverageStatus;
use App\Message\RunPlanningJob;
use App\Repository\PlanningJobRepository;
use App\Service\CompletionLineResult;
use App\Service\LaunchLineResult;
use App\Service\LaunchResultPresenter;
use App\Service\PlanningCompletionService;
use App\Service\PlanningGenerationLauncher;
use App\Service\PlanningJobHeartbeat;
use App\Service\PlanningJobStore;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * The worker side of an engine job (docs/decisions.md D149). It adds no
 * engine logic: GENERATE calls PlanningGenerationLauncher::launch() and
 * COMPLETE calls PlanningCompletionService::complete() — the very services
 * the synchronous endpoints used to call, with their own locks, preflight
 * re-check, atomic writes and stale-calendar refusal unchanged.
 *
 * Its own job is the lifecycle: claim (QUEUED → RUNNING, atomically, so a
 * redelivered message or a second worker does nothing), heartbeat while
 * working, then a terminal state — SUCCEEDED, or FAILED with a stable code.
 * It never rethrows: every failure is recorded on the job (with the
 * internal detail kept server-side and logged), so there is nothing for
 * Messenger to retry — the manager relaunches on purpose.
 */
#[AsMessageHandler]
final class RunPlanningJobHandler
{
    public function __construct(
        private readonly PlanningJobRepository $jobRepository,
        private readonly PlanningJobStore $store,
        private readonly PlanningJobHeartbeat $heartbeat,
        private readonly PlanningGenerationLauncher $launcher,
        private readonly PlanningCompletionService $completionService,
        private readonly LaunchResultPresenter $launchResultPresenter,
        private readonly EntityManagerInterface $entityManager,
        private readonly ManagerRegistry $registry,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(RunPlanningJob $message): void
    {
        $job = $this->jobRepository->find($message->jobId);
        if (null === $job || !$this->store->claim($message->jobId)) {
            // Already claimed, finished or recovered as FAILED: a redelivery, never a second run.
            return;
        }

        $this->heartbeat->start($message->jobId);
        $baseNestingLevel = $this->entityManager->getConnection()->getTransactionNestingLevel();
        try {
            [$outcome, $failureCode] = PlanningJobKind::GENERATE === $job->getKind() ? $this->generate($job) : $this->complete($job);

            if (null === $failureCode) {
                $this->store->succeed($message->jobId, $outcome);
            } else {
                $this->store->fail($message->jobId, $failureCode, null, $outcome);
            }
        } catch (\Throwable $exception) {
            $this->recordFailure($job, $exception, $baseNestingLevel);
        } finally {
            $this->heartbeat->stop();
        }
    }

    /**
     * @return array{0: array<string, mixed>, 1: ?string}
     */
    private function generate(PlanningJob $job): array
    {
        $policy = $job->getRestPolicy() ?? [];
        $restPolicy = new RestPolicyOptions(
            (bool) ($policy['legalMinRestEnabled'] ?? false),
            isset($policy['legalMinRestHours']) ? (int) $policy['legalMinRestHours'] : null,
            (bool) ($policy['teamMinRestEnabled'] ?? false),
            isset($policy['teamMinRestHours']) ? (int) $policy['teamMinRestHours'] : null,
        );

        $results = $this->launcher->launch($job->getPlanning(), $job->getRequestedBy(), $restPolicy);

        $lines = array_map($this->launchResultPresenter->lineToArray(...), $results);
        $usable = array_filter($results, static fn (LaunchLineResult $r): bool => PlanningGenerationStatus::COMPLETED === $r->generation?->getStatus());
        // A conditional line whose demand could not be fully determined (a source duty nobody held, D164) is
        // incomplete too — never reported as complete because the undecided units were left out of its problem.
        $incomplete = array_filter($usable, static fn (LaunchLineResult $r): bool => CoverageStatus::INCOMPLETE === $r->result?->coverageStatus || ($r->demand?->undeterminedUnitCount ?? 0) > 0);

        $outcome = [
            'lines' => $lines,
            'coverage' => \count($usable) === \count($results) ? ([] === $incomplete ? 'COMPLETE' : 'INCOMPLETE') : null,
        ];

        // A line that produced no usable result (solver error, timeout, stale data) makes the whole job
        // FAILED — its per-line detail stays in the outcome — never a success that hides it.
        return [$outcome, \count($usable) === \count($results) ? null : 'generation_failed'];
    }

    /**
     * @return array{0: array<string, mixed>, 1: ?string}
     */
    private function complete(PlanningJob $job): array
    {
        $results = $this->completionService->complete($job->getPlanning(), $job->getRequestedBy());

        $remaining = array_sum(array_map(static fn (CompletionLineResult $r): int => $r->remainingUncoveredRequiredUnitCount, $results));
        $failed = array_filter($results, static fn (CompletionLineResult $r): bool => 'solver_failed' === $r->status);

        return [
            [
                'lines' => array_map(static fn (CompletionLineResult $r): array => $r->toArray(), $results),
                'coverage' => 0 === $remaining ? 'COMPLETE' : 'INCOMPLETE',
            ],
            [] === $failed ? null : 'completion_failed',
        ];
    }

    private function recordFailure(PlanningJob $job, \Throwable $exception, int $baseNestingLevel): void
    {
        $code = match (true) {
            $exception instanceof PlanningNotLaunchableException => 'not_launchable',
            $exception instanceof PlanningCompletionStaleException => 'calendar_changed',
            $exception instanceof NoSolverParameterSetException => 'no_solver_parameter_set',
            $exception instanceof PlanningGenerationInProgressException => 'engine_busy',
            default => 'unexpected_error',
        };

        if ('unexpected_error' === $code) {
            $this->logger->error('PlanningJob {id} failed: {class}: {message}', [
                'id' => $job->getId(),
                'class' => $exception::class,
                'message' => $exception->getMessage(),
                'exception' => $exception,
            ]);
        }

        // The failing step may have left a transaction open or closed the EntityManager: record the
        // failure on a clean connection state, with plain SQL (PlanningJobStore).
        $connection = $this->entityManager->getConnection();
        while ($connection->getTransactionNestingLevel() > $baseNestingLevel) {
            $connection->rollBack();
        }
        if (!$this->entityManager->isOpen()) {
            $this->registry->resetManager();
        }

        $planningId = (int) $job->getPlanning()->getId();
        $this->store->fail((int) $job->getId(), $code, $exception::class.': '.$exception->getMessage());
        $this->store->failSolvingGenerations($planningId, 'Interrupted: '.$code);
    }
}
