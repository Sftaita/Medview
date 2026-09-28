<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Duty;
use App\Entity\DutyAssignment;
use App\Entity\DutyAssignmentEvent;
use App\Entity\PlanningGeneration;
use App\Entity\PlanningTeamMember;
use App\Entity\User;
use App\Exception\CoverageNotRequiredException;
use App\Exception\CoverageUndeterminedException;
use App\Exception\DutyAlreadyUncoveredException;
use App\Exception\DutyNotGeneratedException;
use App\Exception\InvalidReassignmentCandidateException;
use App\Exception\PlanningGenerationNotSnapshottedException;
use App\Exception\StaleReassignmentException;
use App\Repository\DutyAssignmentRepository;
use App\Repository\PlanningGenerationRepository;
use App\Repository\PlanningSnapshotRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The write paths of the dynamic calendar (docs/decisions.md D131/D144):
 * replacing whoever holds a Duty — or its whole atomic block, never one day
 * of it (§15: never "samedi → Dupont, dimanche → Martin") — and removing
 * them without replacement ("Retirer l'affectation", which deliberately
 * leaves the block NON COUVERT). Both revalidate against live data at save
 * time (never the editor's possibly-stale list) and persist nothing before
 * succeeding completely.
 *
 * Concurrency: the whole read-check-write sequence runs inside one
 * transaction that first takes the Planning's CalendarWriteLock, so two
 * simultaneous saves are serialized and the second one sees the first
 * one's result (StaleReassignmentException, 409) — the D131 identity check
 * alone could not catch two requests that read before either committed.
 *
 * Statement order matters and is the reason this does NOT follow the
 * project's usual "one flush() for the whole batch" convention
 * (docs/planning-generation.md §Atomicité): the partial unique index on
 * `(generation_id, duty_id) WHERE current` is checked immediately per
 * statement, not deferred to commit. Inserting the new current row before
 * the old one is marked superseded would violate it mid-transaction even
 * though the final state is valid. So each write flushes twice inside the
 * transaction — supersede first, then insert — a deliberate, documented
 * exception to the single-flush convention. Either flush failing rolls back
 * everything.
 */
final class DutyReassignmentService
{
    public function __construct(
        private readonly PlanningGenerationRepository $generationRepository,
        private readonly PlanningSnapshotRepository $snapshotRepository,
        private readonly DutyAssignmentRepository $assignmentRepository,
        private readonly ReassignmentCandidateService $candidateService,
        private readonly DutyAssignmentService $dutyAssignmentService,
        private readonly CalendarWriteLock $calendarWriteLock,
        private readonly EntityManagerInterface $entityManager,
        private readonly ConditionalCoverageService $conditionalCoverage,
    ) {
    }

    /**
     * @throws DutyNotGeneratedException
     * @throws StaleReassignmentException
     * @throws InvalidReassignmentCandidateException
     * @throws PlanningGenerationNotSnapshottedException
     * @throws CoverageNotRequiredException            a new assignment on a reinforcement the live demand does not require (D165)
     * @throws CoverageUndeterminedException           a new assignment on a reinforcement whose demand cannot be evaluated (D165)
     *
     * @return list<DependentImpact> what the change did to the reinforcements depending on this block (D165) — reported, never acted on
     */
    public function reassign(
        Duty $representativeDuty,
        PlanningTeamMember $chosenMember,
        ?string $expectedCurrentTeamMemberStableId,
        User $author,
        bool $wasPublished,
    ): array {
        return $this->inLockedTransaction($representativeDuty, function () use ($representativeDuty, $chosenMember, $expectedCurrentTeamMemberStableId, $author, $wasPublished): array {
            [$generation, $block, $currentByDuty] = $this->resolveCurrentState($representativeDuty, $expectedCurrentTeamMemberStableId);

            // docs/decisions.md D165: a conditional block takes a NEW holder only while the live demand requires it —
            // replacing the holder of a superfluous reinforcement is a new assignment too, and is refused alike.
            $this->conditionalCoverage->assertCanBeNewlyCovered($block);

            $error = $this->candidateService->assignabilityError($generation, $block, $chosenMember);
            if (null !== $error) {
                throw new InvalidReassignmentCandidateException($error);
            }

            $snapshot = $this->snapshotRepository->findOneByGeneration($generation);
            if (null === $snapshot) {
                throw new PlanningGenerationNotSnapshottedException();
            }

            $dependents = $this->conditionalCoverage->captureDependents($block);

            // Phase 1 — supersede every current row of the block (UPDATE only), flushed on its own
            // so the INSERTs below never race the partial unique index (see class docblock).
            $this->supersede($block, $currentByDuty);

            // Phase 2 — one new MANUAL DutyAssignment + one DutyAssignmentEvent per constituent Duty.
            $occurredAt = new \DateTimeImmutable();
            $planning = $representativeDuty->getPlanningPeriod()->getTeam()->getPlanning();
            foreach ($block as $duty) {
                $previous = $currentByDuty[(int) $duty->getId()] ?? null;
                $new = $this->dutyAssignmentService->createManualBatchItem($generation, $snapshot, $duty, $chosenMember);
                $this->entityManager->persist(new DutyAssignmentEvent($planning, $generation, $duty, $previous, $new, $author, $wasPublished, $occurredAt));
            }
            $this->entityManager->flush();

            return $this->conditionalCoverage->impactsSince($dependents);
        });
    }

    /**
     * "Retirer l'affectation" (docs/decisions.md D144): the whole block
     * becomes uncovered — a deliberate intermediate state of the calendar,
     * not an error. Its previous rows are superseded (never deleted) and one
     * event per constituent Duty records the removal (newAssignment = null).
     *
     * @throws DutyNotGeneratedException
     * @throws StaleReassignmentException    the block no longer has the holder the editor showed
     * @throws DutyAlreadyUncoveredException there is nobody to remove
     *
     * @return list<DependentImpact> docs/decisions.md D165 — removing a source holder may leave its reinforcements undetermined
     */
    public function unassign(Duty $representativeDuty, ?string $expectedCurrentTeamMemberStableId, User $author, bool $wasPublished): array
    {
        return $this->inLockedTransaction($representativeDuty, function () use ($representativeDuty, $expectedCurrentTeamMemberStableId, $author, $wasPublished): array {
            [$generation, $block, $currentByDuty] = $this->resolveCurrentState($representativeDuty, $expectedCurrentTeamMemberStableId);

            if ([] === $currentByDuty) {
                throw new DutyAlreadyUncoveredException();
            }

            $dependents = $this->conditionalCoverage->captureDependents($block);
            $this->supersede($block, $currentByDuty);

            $occurredAt = new \DateTimeImmutable();
            $planning = $representativeDuty->getPlanningPeriod()->getTeam()->getPlanning();
            foreach ($block as $duty) {
                $previous = $currentByDuty[(int) $duty->getId()] ?? null;
                if (null !== $previous) {
                    $this->entityManager->persist(new DutyAssignmentEvent($planning, $generation, $duty, $previous, null, $author, $wasPublished, $occurredAt));
                }
            }
            $this->entityManager->flush();

            return $this->conditionalCoverage->impactsSince($dependents);
        });
    }

    /**
     * Resolves the block and its current state *after* the lock is held, and
     * enforces the D131 identity check against it.
     *
     * @return array{0: PlanningGeneration, 1: list<Duty>, 2: array<int, DutyAssignment>}
     *
     * @throws DutyNotGeneratedException
     * @throws StaleReassignmentException
     */
    private function resolveCurrentState(Duty $representativeDuty, ?string $expectedCurrentTeamMemberStableId): array
    {
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

        return [$generation, $block, $currentByDuty];
    }

    /**
     * @param list<Duty>                 $block
     * @param array<int, DutyAssignment> $currentByDuty
     */
    private function supersede(array $block, array $currentByDuty): void
    {
        foreach ($block as $duty) {
            // `??` first: an uncovered duty has no key here at all — `?->` alone would still warn.
            ($currentByDuty[(int) $duty->getId()] ?? null)?->markSuperseded();
        }
        $this->entityManager->flush();
    }

    /**
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    private function inLockedTransaction(Duty $representativeDuty, callable $work): mixed
    {
        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();
        try {
            $this->calendarWriteLock->acquire($representativeDuty->getPlanningPeriod()->getTeam()->getPlanning());
            $result = $work();
            $connection->commit();

            return $result;
        } catch (UniqueConstraintViolationException) {
            // Last line of defense: the lock makes this unreachable through the application, but a
            // concurrent writer outside it must still surface as "the calendar changed", never a 500.
            $connection->rollBack();
            throw new StaleReassignmentException();
        } catch (\Throwable $exception) {
            $connection->rollBack();
            throw $exception;
        }
    }
}
