<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\FairnessPeriod;
use App\Entity\PlanningPeriod;
use App\Entity\PlanningPeriodStatus;
use App\Entity\PlanningTeam;
use App\Exception\InvalidPlanningPeriodTransitionException;
use App\Exception\PlanningPeriodNotReadyToPublishException;
use App\Repository\DutyAssignmentRepository;
use App\Repository\DutyRepository;
use App\Repository\PlanningGenerationRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Centralizes PlanningPeriod creation and lifecycle transitions
 * (docs/allocation-algorithm.md §18) so the legal transition graph is
 * enforced in exactly one place, never scattered across future
 * controllers.
 *
 * docs/decisions.md D106 originally required `coverageStatus = COMPLETE`
 * on the most recent COMPLETED PlanningGeneration to reach PUBLISHED — the
 * *historical*, solver-time value. D133 (Sub-lot C, the first and still
 * only real caller of this branch) found that check stale by construction
 * once Sub-lot A (D131) allowed manual reassignment: a generation whose
 * historical result was INCOMPLETE can have a fully-covered *current*
 * calendar (a manager filled the last duty by hand), and that must be
 * publishable. The check below now counts live, uncovered REQUIRED duties
 * from the current `DutyAssignment` state — never the frozen
 * `coverageStatus` — consistent with `PlanningPublicationPreflightService`,
 * which re-validates far more than just this before ever calling here.
 */
final class PlanningPeriodLifecycleService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PlanningGenerationRepository $generationRepository,
        private readonly DutyRepository $dutyRepository,
        private readonly DutyAssignmentRepository $assignmentRepository,
    ) {
    }

    public function create(
        PlanningTeam $team,
        FairnessPeriod $fairnessPeriod,
        string $name,
        \DateTimeImmutable $startsAt,
        \DateTimeImmutable $endsAt,
    ): PlanningPeriod {
        $planningPeriod = new PlanningPeriod($team, $fairnessPeriod, $name, $startsAt, $endsAt);
        $this->entityManager->persist($planningPeriod);
        $this->entityManager->flush();

        return $planningPeriod;
    }

    /**
     * @throws InvalidPlanningPeriodTransitionException
     * @throws PlanningPeriodNotReadyToPublishException
     */
    public function transition(PlanningPeriod $planningPeriod, PlanningPeriodStatus $target): void
    {
        // The state-machine SHAPE is checked first — an illegal transition
        // (e.g. DRAFT → PUBLISHED directly) must still fail with
        // InvalidPlanningPeriodTransitionException, never be pre-empted by
        // the coverage precondition below, which only makes sense once the
        // transition is otherwise legal.
        if (!$planningPeriod->getStatus()->canTransitionTo($target)) {
            throw new InvalidPlanningPeriodTransitionException($planningPeriod->getStatus(), $target);
        }

        if (PlanningPeriodStatus::PUBLISHED === $target && !$this->isCurrentlyFullyCovered($planningPeriod)) {
            throw new PlanningPeriodNotReadyToPublishException();
        }

        $planningPeriod->transitionTo($target);
        $this->entityManager->flush();
    }

    /**
     * Every REQUIRED Duty of this period currently has a DutyAssignment
     * (D131: `current = true`) — the live calendar, never the generation's
     * frozen `coverageStatus` (D133).
     */
    private function isCurrentlyFullyCovered(PlanningPeriod $planningPeriod): bool
    {
        $generation = $this->generationRepository->findMostRecentCompletedByPlanningPeriod($planningPeriod);
        if (null === $generation) {
            return false;
        }

        $assignedDutyIds = [];
        foreach ($this->assignmentRepository->findForGenerations([$generation]) as $assignment) {
            $assignedDutyIds[(int) $assignment->getDuty()->getId()] = true;
        }

        foreach ($this->dutyRepository->findByPlanningPeriod($planningPeriod) as $duty) {
            if ($duty->isRequired() && !isset($assignedDutyIds[(int) $duty->getId()])) {
                return false;
            }
        }

        return true;
    }
}
