<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Planning;
use App\Entity\PlanningJob;
use App\Entity\PlanningJobKind;
use App\Entity\RestPolicyOptions;
use App\Entity\User;
use App\Exception\PlanningJobInProgressException;
use App\Exception\PlanningNotLaunchableException;
use App\Exception\SurgicalHubDataStaleException;
use App\Message\RunPlanningJob;
use App\Repository\PlanningJobRepository;
use App\Service\SurgicalHub\SurgicalHubFreshnessGate;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The HTTP side of the engine jobs (docs/decisions.md D149): accept a
 * request quickly, never run OR-Tools here.
 *
 * Requesting a job = the cheap synchronous checks (GENERATE: the same
 * preflight as before — a technically impossible generation is still
 * refused immediately with its blockers), then one row QUEUED, then one
 * message. The "one active job per Planning" rule is the database's
 * (partial unique index): two simultaneous requests — double click, two
 * managers, a completion during a generation — cannot both insert, and the
 * loser gets PlanningJobInProgressException with the job that won.
 */
final class PlanningJobService
{
    public function __construct(
        private readonly PlanningJobRepository $jobRepository,
        private readonly PlanningGenerationLauncher $launcher,
        private readonly PlanningJobRecovery $recovery,
        private readonly PlanningJobStore $store,
        private readonly MessageBusInterface $bus,
        private readonly EntityManagerInterface $entityManager,
        private readonly ManagerRegistry $registry,
        private readonly SurgicalHubFreshnessGate $surgicalHubGate,
    ) {
    }

    /**
     * SurgicalHub leave is refreshed last, just before queuing — the snapshot
     * the worker takes next reads it (docs/surgicalhub-integration.md §7.5, D8);
     * the worker itself never calls SurgicalHub.
     *
     * @throws PlanningNotLaunchableException
     * @throws PlanningJobInProgressException
     * @throws SurgicalHubDataStaleException  outdated SurgicalHub leave and no allowed override
     */
    public function requestGeneration(Planning $planning, User $requestedBy, RestPolicyOptions $restPolicy, bool $overrideStaleSurgicalHubData = false): RequestedGeneration
    {
        $this->recovery->recover((int) $planning->getId());

        $active = $this->jobRepository->findActiveForPlanning($planning);
        if (null !== $active) {
            throw new PlanningJobInProgressException($active);
        }

        $preflight = $this->launcher->preflight($planning);
        if (!$preflight->canGenerate()) {
            throw new PlanningNotLaunchableException($preflight);
        }

        $surgicalHub = $this->surgicalHubGate->check($planning, $requestedBy, $overrideStaleSurgicalHubData);

        $job = $this->enqueue(new PlanningJob($planning, PlanningJobKind::GENERATE, $requestedBy, [
            'legalMinRestEnabled' => $restPolicy->legalMinRestEnabled,
            'legalMinRestHours' => $restPolicy->legalMinRestHours,
            'teamMinRestEnabled' => $restPolicy->teamMinRestEnabled,
            'teamMinRestHours' => $restPolicy->teamMinRestHours,
        ]));
        $this->surgicalHubGate->recordOverride($surgicalHub, $planning, $requestedBy, (string) $job->getStableId());

        return new RequestedGeneration($job, $surgicalHub);
    }

    /**
     * @throws PlanningJobInProgressException
     */
    public function requestCompletion(Planning $planning, User $requestedBy): PlanningJob
    {
        $this->recovery->recover((int) $planning->getId());

        $active = $this->jobRepository->findActiveForPlanning($planning);
        if (null !== $active) {
            throw new PlanningJobInProgressException($active);
        }

        return $this->enqueue(new PlanningJob($planning, PlanningJobKind::COMPLETE, $requestedBy));
    }

    /** The state the screen shows — a job whose worker died is failed first, never shown as running. */
    public function latest(Planning $planning): ?PlanningJob
    {
        if ($this->recovery->recover((int) $planning->getId()) > 0) {
            $this->entityManager->clear();
        }

        return $this->jobRepository->findLatestForPlanning($planning);
    }

    /**
     * @throws PlanningJobInProgressException
     */
    private function enqueue(PlanningJob $job): PlanningJob
    {
        try {
            $this->entityManager->persist($job);
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException) {
            // Lost the race to a concurrent request (the partial unique index on active jobs).
            // The EntityManager is closed by the failed flush: read the winner with a fresh one.
            $this->registry->resetManager();
            /** @var PlanningJobRepository $repository */
            $repository = $this->registry->getRepository(PlanningJob::class);
            $planning = $this->registry->getManager()->getReference(Planning::class, $job->getPlanning()->getId());

            throw new PlanningJobInProgressException($repository->findActiveForPlanning($planning));
        }

        try {
            $this->bus->dispatch(new RunPlanningJob((int) $job->getId()));
        } catch (\Throwable $exception) {
            // Never leave a QUEUED job no message will ever run: it would block the Planning until recovery.
            $this->store->fail((int) $job->getId(), 'dispatch_failed', $exception::class.': '.$exception->getMessage());
            throw $exception;
        }

        return $job;
    }
}
