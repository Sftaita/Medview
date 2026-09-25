<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Duty;
use App\Entity\DutyAssignmentEvent;
use App\Entity\PlanningTeamMember;
use App\Entity\User;
use App\Exception\DutyNotGeneratedException;
use App\Exception\InvalidReassignmentCandidateException;
use App\Exception\PlanningGenerationNotSnapshottedException;
use App\Exception\StaleReassignmentException;
use App\Repository\DutyAssignmentRepository;
use App\Repository\PlanningGenerationRepository;
use App\Repository\PlanningSnapshotRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The one write path for a manual reassignment (docs/decisions.md D131) —
 * atomic across the whole block (§15: never "samedi → Dupont, dimanche →
 * Martin"), revalidated for real against live data (never the modal's
 * possibly-stale list, §17), and never an optimistic UI write: nothing is
 * persisted before this method returns successfully.
 *
 * Statement order matters and is the reason this does NOT follow the
 * project's usual "one flush() for the whole batch" convention
 * (docs/planning-generation.md §Atomicité): the partial unique index on
 * `(generation_id, duty_id) WHERE current` is checked immediately per
 * statement, not deferred to commit (Postgres cannot defer a
 * partial-index-backed constraint). Inserting the new current row before
 * the old one is marked superseded would violate it mid-transaction even
 * though the final state is valid. So this explicitly opens one database
 * transaction and flushes twice inside it — supersede first, then insert —
 * a deliberate, documented exception to the single-flush convention, not
 * an oversight. Either flush failing rolls back everything, so the two
 * statements are still atomic together.
 */
final class DutyReassignmentService
{
    public function __construct(
        private readonly PlanningGenerationRepository $generationRepository,
        private readonly PlanningSnapshotRepository $snapshotRepository,
        private readonly DutyAssignmentRepository $assignmentRepository,
        private readonly ReassignmentCandidateService $candidateService,
        private readonly DutyAssignmentService $dutyAssignmentService,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @throws DutyNotGeneratedException
     * @throws StaleReassignmentException
     * @throws InvalidReassignmentCandidateException
     * @throws PlanningGenerationNotSnapshottedException
     */
    public function reassign(
        Duty $representativeDuty,
        PlanningTeamMember $chosenMember,
        ?string $expectedCurrentTeamMemberStableId,
        User $author,
        bool $wasPublished,
    ): void {
        $generation = $this->generationRepository->findMostRecentCompletedByPlanningPeriod($representativeDuty->getPlanningPeriod());
        if (null === $generation) {
            throw new DutyNotGeneratedException();
        }

        $block = $this->candidateService->blockDuties($representativeDuty);
        $currentByDuty = $this->assignmentRepository->findCurrentByGenerationAndDuties($generation, $block);
        $currentMember = $this->candidateService->currentBlockTeamMember($block, $currentByDuty);

        $actualCurrentId = null !== $currentMember ? (string) $currentMember->getStableId() : null;
        if ($actualCurrentId !== $expectedCurrentTeamMemberStableId) {
            throw new StaleReassignmentException();
        }

        $blockingReason = $this->candidateService->firstBlockingReason($generation, $block, $chosenMember);
        if (null !== $blockingReason) {
            throw new InvalidReassignmentCandidateException($blockingReason->value);
        }

        $snapshot = $this->snapshotRepository->findOneByGeneration($generation);
        if (null === $snapshot) {
            throw new PlanningGenerationNotSnapshottedException();
        }

        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();
        try {
            // Phase 1 — supersede every current row of the block (UPDATE
            // only). Flushed on its own so the second phase's INSERTs never
            // race the partial unique index (see class docblock).
            foreach ($block as $duty) {
                // `??` first: a previously-uncovered duty (D130) has no key here at all — not merely a
                // null value — and `?->` alone does not guard against that (PHP still warns on the
                // missing array key before the null-safe operator ever sees it).
                ($currentByDuty[(int) $duty->getId()] ?? null)?->markSuperseded();
            }
            $this->entityManager->flush();

            // Phase 2 — one new MANUAL DutyAssignment + one DutyAssignmentEvent
            // per constituent Duty (INSERT only), all still uncommitted.
            $occurredAt = new \DateTimeImmutable();
            $planning = $representativeDuty->getPlanningPeriod()->getTeam()->getPlanning();
            foreach ($block as $duty) {
                $previous = $currentByDuty[(int) $duty->getId()] ?? null;
                $new = $this->dutyAssignmentService->createManualBatchItem($generation, $snapshot, $duty, $chosenMember);
                $event = new DutyAssignmentEvent($planning, $generation, $duty, $previous, $new, $author, $wasPublished, $occurredAt);
                $this->entityManager->persist($event);
            }
            $this->entityManager->flush();

            $connection->commit();
        } catch (\Throwable $exception) {
            $connection->rollBack();
            throw $exception;
        }
    }
}
