<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Planning;
use App\Entity\PlanningCycle;
use App\Entity\PlanningJob;
use App\Entity\PlanningJobKind;
use App\Entity\PlanningLine;
use App\Entity\RestPolicyOptions;
use App\Entity\User;
use App\Exception\NothingToFillException;
use App\Exception\PlanningJobInProgressException;
use App\Exception\PlanningNotLaunchableException;
use App\Exception\PublishedLinesNotCompletedException;
use App\Exception\SurgicalHubDataStaleException;
use App\Message\RunPlanningJob;
use App\Repository\PlanningGenerationRepository;
use App\Repository\PlanningJobRepository;
use App\Service\SurgicalHub\SurgicalHubFreshnessGate;
use App\Service\SurgicalHub\SurgicalHubFreshnessReport;
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
        private readonly PeriodPreparationService $preparationService,
        private readonly PlanningLineFillService $fillService,
        private readonly PlanningLineOrder $lineOrder,
        private readonly PlanningGenerationRepository $generationRepository,
        private readonly PlanningCompletionService $completionService,
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
    public function requestGeneration(Planning $planning, User $requestedBy, RestPolicyOptions $restPolicy, bool $overrideStaleSurgicalHubData = false, ?PlanningCycle $cycle = null, ?string $scope = null, bool $replaceExisting = false): RequestedGeneration
    {
        // docs/planning-cycles.md §5.4 (lot L3): one period per job, named by the caller on a planning with several.
        $cycle ??= $planning->getOnlyCycle();
        $this->recovery->recover((int) $planning->getId());

        $active = $this->jobRepository->findActiveForPlanning($planning);
        if (null !== $active) {
            throw new PlanningJobInProgressException($active);
        }

        // docs/decisions.md D195: the same scope the worker replays — a period with nothing to generate never queues a job.
        // docs/decisions.md D222: a line already generated is only generated again on explicit confirmation.
        $preflight = $this->launcher->preflight($planning, $cycle, $scope, null, $replaceExisting);
        if (!$preflight->canGenerate()) {
            throw new PlanningNotLaunchableException($preflight);
        }

        $surgicalHub = $this->surgicalHubGate->check($planning, $requestedBy, $overrideStaleSurgicalHubData);

        $job = $this->enqueue(new PlanningJob($planning, PlanningJobKind::GENERATE, $requestedBy, [
            'legalMinRestEnabled' => $restPolicy->legalMinRestEnabled,
            'legalMinRestHours' => $restPolicy->legalMinRestHours,
            'teamMinRestEnabled' => $restPolicy->teamMinRestEnabled,
            'teamMinRestHours' => $restPolicy->teamMinRestHours,
        ], $scope, null, $replaceExisting), $cycle);
        $this->surgicalHubGate->recordOverride($surgicalHub, $planning, $requestedBy, (string) $job->getStableId());
        // docs/decisions.md D210: the generation is launched — the reminders not sent yet are pointless.
        $this->preparationService->onGenerationRequested($cycle);

        return new RequestedGeneration($job, $surgicalHub);
    }

    /**
     * docs/decisions.md D223: refused, with no job queued, when every
     * generated line of the period is published, validated or archived —
     * none of them is ever completed automatically. When some are and others
     * are not, the job runs and the locked ones are reported as left alone
     * (PlanningCompletionService).
     *
     * @throws PlanningJobInProgressException
     * @throws PublishedLinesNotCompletedException
     */
    public function requestCompletion(Planning $planning, User $requestedBy, ?PlanningCycle $cycle = null): PlanningJob
    {
        $cycle ??= $planning->getOnlyCycle();
        $this->recovery->recover((int) $planning->getId());

        $active = $this->jobRepository->findActiveForPlanning($planning);
        if (null !== $active) {
            throw new PlanningJobInProgressException($active);
        }

        $generated = array_values(array_filter(
            $this->lineOrder->activeInCycle($cycle),
            fn (PlanningLine $line): bool => null !== ($period = $line->periodIn($cycle)) && null !== $this->generationRepository->findMostRecentCompletedByPlanningPeriod($period),
        ));
        // docs/decisions.md D226: a published line with duties its extension created still has something to receive.
        $locked = array_values(array_filter($generated, fn (PlanningLine $line): bool => null !== PlanningCompletionService::lockedReason($line, $cycle) && 0 === $this->completionService->extensionHoleCount($line, $cycle)));
        if ([] !== $locked && \count($locked) === \count($generated)) {
            throw new PublishedLinesNotCompletedException($locked);
        }

        return $this->enqueue(new PlanningJob($planning, PlanningJobKind::COMPLETE, $requestedBy), $cycle);
    }

    /**
     * "Générer les postes non pourvus" / "Générer cette ligne"
     * (docs/decisions.md D222). Queued only when at least one line asked for
     * has something to receive; the SurgicalHub check and the end of the
     * period's preparation reminders apply as soon as a line is given its
     * first generation — never for a completion alone, as before.
     *
     * @param list<string> $lineStableIds
     *
     * @throws \InvalidArgumentException      a line asked for is not an active line of the period
     * @throws NothingToFillException         nothing to generate nor to complete on the lines asked for
     * @throws PlanningJobInProgressException
     * @throws SurgicalHubDataStaleException  a first generation with outdated SurgicalHub leave and no allowed override
     */
    public function requestFill(Planning $planning, User $requestedBy, PlanningCycle $cycle, array $lineStableIds, RestPolicyOptions $restPolicy, bool $overrideStaleSurgicalHubData = false): RequestedGeneration
    {
        $this->recovery->recover((int) $planning->getId());
        $active = $this->jobRepository->findActiveForPlanning($planning);
        if (null !== $active) {
            throw new PlanningJobInProgressException($active);
        }

        $preview = $this->fillService->preview($planning, $cycle);
        $known = array_map(static fn (FillLinePlan $plan): string => (string) $plan->line->getStableId(), $preview->lines);
        $lineStableIds = array_values(array_unique($lineStableIds));
        if ([] === $lineStableIds || [] !== array_diff($lineStableIds, $known)) {
            throw new \InvalidArgumentException('Every line asked for must be an active line of the period.');
        }
        $writing = $preview->writing($lineStableIds);
        if ([] === $writing || [] !== $preview->blockers) {
            throw new NothingToFillException($preview);
        }

        $generates = [] !== array_filter($writing, static fn (FillLinePlan $plan): bool => FillLinePlan::GENERATE === $plan->action);
        $surgicalHub = $generates
            ? $this->surgicalHubGate->check($planning, $requestedBy, $overrideStaleSurgicalHubData)
            : new SurgicalHubFreshnessReport([]);

        $job = $this->enqueue(new PlanningJob($planning, PlanningJobKind::FILL, $requestedBy, [
            'legalMinRestEnabled' => $restPolicy->legalMinRestEnabled,
            'legalMinRestHours' => $restPolicy->legalMinRestHours,
            'teamMinRestEnabled' => $restPolicy->teamMinRestEnabled,
            'teamMinRestHours' => $restPolicy->teamMinRestHours,
        ], null, $lineStableIds), $cycle);
        if ($generates) {
            $this->surgicalHubGate->recordOverride($surgicalHub, $planning, $requestedBy, (string) $job->getStableId());
            // docs/decisions.md D210: a generation is launched — the reminders not sent yet are pointless.
            $this->preparationService->onGenerationRequested($cycle);
        }

        return new RequestedGeneration($job, $surgicalHub);
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
    private function enqueue(PlanningJob $job, PlanningCycle $cycle): PlanningJob
    {
        // The period the worker generates or completes (lot L3) — still one active job per Planning, whatever the
        // period: two periods are never computed at the same time, so neither reads the other mid-write.
        $job->assignToCycle($cycle);

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
