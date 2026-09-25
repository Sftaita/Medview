<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Planning;
use App\Entity\PlanningLine;
use App\Entity\PlanningPeriodStatus;
use App\Entity\User;
use App\Exception\PlanningAlreadyPublishedException;
use App\Exception\PlanningNotPublishableException;
use App\Exception\PlanningPublicationInProgressException;
use App\Repository\PlanningLineRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * "Publier le planning" (docs/decisions.md D133) — a Planning-level facade
 * over the per-line `PlanningPeriodStatus` lifecycle, the exact same shape
 * as generation (D129, `PlanningGenerationLauncher`): `PlanningPeriod`
 * lives on the line, not the Planning, so publishing "the planning" means
 * transitioning every active line's period.
 *
 * Publication is never a lock: it marks the *current* calendar as
 * officially communicated, and the calendar stays editable afterward
 * (Sub-lot A's reassignment is untouched by this service). Always goes
 * through `PlanningPeriodLifecycleService::transition()` — never a direct
 * `$period->transitionTo()` bypass — including the mandatory
 * GENERATED → VALIDATED step: audited (§12/§3 of D132's spec) as having no
 * real distinct business meaning today, so it is never exposed as its own
 * user-facing action, only silently traversed here.
 */
final class PlanningPublicationService
{
    private const ADVISORY_LOCK_NAMESPACE = 7353;

    public function __construct(
        private readonly PlanningLineRepository $lineRepository,
        private readonly PlanningPublicationPreflightService $preflightService,
        private readonly PlanningPeriodLifecycleService $lifecycleService,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return list<PublicationLineResult>
     *
     * @throws PlanningPublicationInProgressException
     * @throws PlanningAlreadyPublishedException
     * @throws PlanningNotPublishableException
     */
    public function publish(Planning $planning, User $publishedBy): array
    {
        if (!$this->tryLock($planning)) {
            throw new PlanningPublicationInProgressException();
        }

        try {
            $activeLines = array_values(array_filter($this->lineRepository->findByPlanning($planning), static fn (PlanningLine $line): bool => $line->isActive()));
            if ([] !== $activeLines && $this->allAlreadyPublished($activeLines)) {
                throw new PlanningAlreadyPublishedException();
            }

            // Always re-run the real preflight here — never trust one the client loaded earlier (§11).
            $preflight = $this->preflightService->check($planning);
            if (!$preflight->publishable) {
                throw new PlanningNotPublishableException($preflight);
            }

            $connection = $this->entityManager->getConnection();
            $connection->beginTransaction();
            try {
                $results = [];
                foreach ($activeLines as $line) {
                    $results[] = $this->publishLine($line);
                }
                $connection->commit();
            } catch (\Throwable $exception) {
                $connection->rollBack();
                throw $exception;
            }

            return $results;
        } finally {
            $this->unlock($planning);
        }
    }

    /**
     * @param list<PlanningLine> $lines
     */
    private function allAlreadyPublished(array $lines): bool
    {
        foreach ($lines as $line) {
            if (PlanningPeriodStatus::PUBLISHED !== $line->getPlanningPeriod()->getStatus()) {
                return false;
            }
        }

        return true;
    }

    private function publishLine(PlanningLine $line): PublicationLineResult
    {
        $period = $line->getPlanningPeriod();
        if (PlanningPeriodStatus::PUBLISHED === $period->getStatus()) {
            return new PublicationLineResult($line, $period->getStatus(), alreadyPublished: true);
        }

        if (PlanningPeriodStatus::GENERATED === $period->getStatus()) {
            $this->lifecycleService->transition($period, PlanningPeriodStatus::VALIDATED);
        }
        $this->lifecycleService->transition($period, PlanningPeriodStatus::PUBLISHED);

        return new PublicationLineResult($line, $period->getStatus(), alreadyPublished: false);
    }

    private function tryLock(Planning $planning): bool
    {
        return (bool) $this->entityManager->getConnection()->fetchOne(
            'SELECT pg_try_advisory_lock(:namespace, :planning)',
            ['namespace' => self::ADVISORY_LOCK_NAMESPACE, 'planning' => $planning->getId()],
        );
    }

    private function unlock(Planning $planning): void
    {
        $this->entityManager->getConnection()->fetchOne(
            'SELECT pg_advisory_unlock(:namespace, :planning)',
            ['namespace' => self::ADVISORY_LOCK_NAMESPACE, 'planning' => $planning->getId()],
        );
    }
}
