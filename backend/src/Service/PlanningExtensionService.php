<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AvailabilityCollection;
use App\Entity\Planning;
use App\Entity\PlanningPeriod;
use App\Entity\PlanningPeriodStatus;
use App\Entity\User;
use App\Exception\InvalidAvailabilityDeadlineException;
use App\Exception\NoNewPlanningRangeException;
use App\Exception\OverlappingFairnessPeriodException;
use App\Exception\PlanningPeriodLockedException;
use App\Exception\PlanningRangeShrinkException;
use App\Repository\FairnessPeriodRepository;
use App\Repository\PlanningLineRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Extends a Planning's date range (docs/availability-collection.md §5,
 * docs/decisions.md D122) and opens an availability collection for the
 * *added* dates only — never for the ones already planned.
 *
 * Atomic: the Planning row is locked and re-read first, so two concurrent
 * extensions are serialized and the second one sees the range the first
 * produced (and is refused as "no new range" instead of opening a second,
 * empty or duplicated collection). Every check that can refuse the request
 * runs before anything is modified.
 *
 * Deliberately conservative: a line whose PlanningPeriod is already
 * VALIDATED/PUBLISHED/ARCHIVED blocks the extension, because a published
 * period is never edited and the engine cannot yet generate an extra slice
 * next to it.
 */
final class PlanningExtensionService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PlanningLineRepository $planningLineRepository,
        private readonly FairnessPeriodRepository $fairnessPeriodRepository,
        private readonly AvailabilityWindowCalculator $windowCalculator,
        private readonly AvailabilityCollectionService $collectionService,
    ) {
    }

    /**
     * @return list<AvailabilityCollection> one per new slice, ordered by date
     *
     * @throws PlanningRangeShrinkException
     * @throws NoNewPlanningRangeException
     * @throws PlanningPeriodLockedException
     * @throws InvalidAvailabilityDeadlineException
     * @throws OverlappingFairnessPeriodException
     */
    public function extend(
        Planning $planning,
        ?\DateTimeImmutable $newStartsAt,
        ?\DateTimeImmutable $newEndsAt,
        User $requestedBy,
        ?\DateTimeImmutable $deadline = null,
    ): array {
        // Before touching anything: a refused deadline must leave the planning as it was.
        $this->collectionService->assertDeadlineNotPast($planning, $deadline);

        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();

        try {
            // Re-read under the lock: the range may have just been extended by a concurrent request.
            $this->entityManager->refresh($planning, LockMode::PESSIMISTIC_WRITE);

            $startsAt = $newStartsAt ?? $planning->getStartsAt();
            $endsAt = $newEndsAt ?? $planning->getEndsAt();
            $windows = $this->windowCalculator->newWindows($planning->getStartsAt(), $planning->getEndsAt(), $startsAt, $endsAt);

            $lines = $this->planningLineRepository->findByPlanning($planning);
            foreach ($lines as $line) {
                $status = $line->getPlanningPeriod()->getStatus();
                if (PlanningPeriodStatus::DRAFT !== $status && PlanningPeriodStatus::GENERATED !== $status) {
                    throw new PlanningPeriodLockedException();
                }
                $this->assertNoOtherFairnessPeriodInTheWay($line->getPlanningPeriod(), $startsAt, $endsAt);
            }

            $planning->extendTo($startsAt, $endsAt);
            foreach ($lines as $line) {
                $period = $line->getPlanningPeriod();
                $period->getFairnessPeriod()->extendTo($startsAt, $endsAt);
                $period->extendTo($startsAt, $endsAt);
            }
            $this->entityManager->flush();

            $collections = [];
            foreach ($windows as $window) {
                $collections[] = $this->collectionService->open($planning, $window, $requestedBy, $deadline);
            }

            $connection->commit();
        } catch (\Throwable $exception) {
            $connection->rollBack();
            throw $exception;
        }

        return $collections;
    }

    private function assertNoOtherFairnessPeriodInTheWay(PlanningPeriod $period, \DateTimeImmutable $startsAt, \DateTimeImmutable $endsAt): void
    {
        $fairnessPeriod = $period->getFairnessPeriod();
        foreach ($this->fairnessPeriodRepository->findOverlapping($period->getTeam(), $startsAt, $endsAt) as $other) {
            if ($other !== $fairnessPeriod) {
                throw new OverlappingFairnessPeriodException();
            }
        }
    }
}
