<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Duty;
use App\Entity\DutyAssignment;
use App\Entity\DutyAssignmentEvent;
use App\Entity\DutySwapProposal;
use App\Entity\PlanningGeneration;
use App\Entity\PlanningPeriodStatus;
use App\Entity\PlanningSnapshot;
use App\Entity\PlanningTeamMember;
use App\Entity\User;
use App\Exception\CoverageNotRequiredException;
use App\Exception\CoverageUndeterminedException;
use App\Exception\DutyAlreadyUncoveredException;
use App\Exception\DutyNotGeneratedException;
use App\Exception\DutySwapNotApplicableException;
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
     * @return list<DependentImpact> what the change did to the reinforcements depending on this block (D165) — reported, never acted on
     *
     * @throws DutyNotGeneratedException
     * @throws StaleReassignmentException
     * @throws InvalidReassignmentCandidateException
     * @throws PlanningGenerationNotSnapshottedException
     * @throws CoverageNotRequiredException              a new assignment on a reinforcement the live demand does not require (D165)
     * @throws CoverageUndeterminedException             a new assignment on a reinforcement whose demand cannot be evaluated (D165)
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
     * @return list<DependentImpact> docs/decisions.md D165 — removing a source holder may leave its reinforcements undetermined
     *
     * @throws DutyNotGeneratedException
     * @throws StaleReassignmentException    the block no longer has the holder the editor showed
     * @throws DutyAlreadyUncoveredException there is nobody to remove
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
     * A swap two members concluded (docs/decisions.md D178): the requester's
     * whole unit goes to the counterpart and the counterpart's whole unit to
     * the requester — both or neither. Must be called inside the caller's
     * transaction (DutySwapService, which also records the workflow step in
     * it); takes the Planning's CalendarWriteLock itself (re-entrant within
     * one transaction), so a swap is serialized with every other write to
     * the calendar: manager edits, completion, publication, other swaps.
     *
     * Everything is revalidated here, against the live state read under the
     * lock — never what was true when the request or proposal was made:
     * the period is PUBLISHED, neither unit has started, each unit is still
     * held through the very DutyAssignment row frozen in the request /
     * proposal (any change since — a manager edit, another swap — makes it
     * DUTY_CHANGED, never silently re-applied), no row is locked, and each
     * person can take the other's unit on the calendar AFTER the swap: the
     * unit they give up is excluded from their own commitments (it would
     * otherwise read as a false CONFLICT / rest violation with the very
     * duty they are handing over), everything else they hold on every line
     * still counts.
     *
     * The writes follow the D131 statement order (supersede every current
     * row of both units first, flushed, then insert the SWAP rows): the
     * partial unique index on current rows is checked per statement. If the
     * swap changed what a dependent conditional line requires
     * (D165), it is refused AFTER the writes — the caller's rollback undoes
     * them; a swap between members never leaves a reinforcement for a
     * manager to fix.
     *
     * @throws DutySwapNotApplicableException
     */
    public function applySwap(DutySwapProposal $proposal, User $author, \DateTimeImmutable $now): SwapApplication
    {
        if (!$this->entityManager->getConnection()->isTransactionActive()) {
            throw new \LogicException('DutyReassignmentService::applySwap() must be called inside a transaction.');
        }

        $request = $proposal->getRequest();
        $this->calendarWriteLock->acquire($request->getPlanning());

        [$generation, $snapshot, $offeredBlock, $counterpartBlock, $offeredCurrent, $counterpartCurrent] = $this->validateSwap($proposal, $now);
        $requesterMember = $request->getRequesterMember();
        $counterpartMember = $proposal->getCounterpartMember();

        $dependents = $this->conditionalCoverage->captureDependents([...$offeredBlock, ...$counterpartBlock]);

        // Phase 1 — supersede every current row of BOTH units, flushed on its own (D131 statement order).
        $this->supersede($offeredBlock, $offeredCurrent);
        $this->supersede($counterpartBlock, $counterpartCurrent);

        // Phase 2 — one SWAP row + one DutyAssignmentEvent (linked to the proposal) per constituent Duty.
        $planning = $request->getPlanning();
        $created = [];
        foreach ([[$offeredBlock, $offeredCurrent, $counterpartMember], [$counterpartBlock, $counterpartCurrent, $requesterMember]] as [$block, $current, $newHolder]) {
            foreach ($block as $duty) {
                $previous = $current[(int) $duty->getId()];
                $new = $this->dutyAssignmentService->createSwapBatchItem($generation, $snapshot, $duty, $newHolder);
                $created[] = $new;
                $this->entityManager->persist(new DutyAssignmentEvent($planning, $generation, $duty, $previous, $new, $author, true, $now, $proposal));
            }
        }
        $this->entityManager->flush();

        foreach ($this->conditionalCoverage->impactsSince($dependents) as $impact) {
            if ($impact->changed()) {
                throw new DutySwapNotApplicableException(DutySwapNotApplicableException::CHANGES_REINFORCEMENTS);
            }
        }

        return new SwapApplication(
            $offeredBlock,
            $counterpartBlock,
            $requesterMember,
            $counterpartMember,
            [...array_values($offeredCurrent), ...array_values($counterpartCurrent)],
            $created,
        );
    }

    /**
     * The same checks as applySwap(), without writing anything — the early
     * feedback given when a proposal is made (docs/duty-swaps.md §6). Never
     * a substitute for applySwap()'s own revalidation at acceptance time,
     * and it cannot foresee CHANGES_REINFORCEMENTS (which needs the write).
     * $proposal may be a not-yet-persisted object.
     *
     * @throws DutySwapNotApplicableException
     */
    public function checkSwap(DutySwapProposal $proposal, \DateTimeImmutable $now): void
    {
        $this->validateSwap($proposal, $now);
    }

    /**
     * @return array{0: PlanningGeneration, 1: PlanningSnapshot, 2: list<Duty>, 3: list<Duty>, 4: array<int, DutyAssignment>, 5: array<int, DutyAssignment>}
     *
     * @throws DutySwapNotApplicableException
     */
    private function validateSwap(DutySwapProposal $proposal, \DateTimeImmutable $now): array
    {
        $request = $proposal->getRequest();
        $offeredDuty = $request->getOfferedDuty();
        $counterpartDuty = $proposal->getCounterpartDuty();

        $period = $offeredDuty->getPlanningPeriod();
        if ($counterpartDuty->getPlanningPeriod() !== $period) {
            throw new DutySwapNotApplicableException(DutySwapNotApplicableException::NOT_SWAPPABLE);
        }
        if (PlanningPeriodStatus::PUBLISHED !== $period->getStatus()) {
            throw new DutySwapNotApplicableException(DutySwapNotApplicableException::PERIOD_NOT_PUBLISHED);
        }

        $generation = $this->generationRepository->findMostRecentCompletedByPlanningPeriod($period);
        if (null === $generation || $request->getOfferedAssignment()->getGeneration() !== $generation || $proposal->getCounterpartAssignment()->getGeneration() !== $generation) {
            throw new DutySwapNotApplicableException(DutySwapNotApplicableException::DUTY_CHANGED);
        }

        $offeredBlock = $this->candidateService->blockDuties($offeredDuty);
        $counterpartBlock = $this->candidateService->blockDuties($counterpartDuty);
        if ([] !== array_uintersect($offeredBlock, $counterpartBlock, static fn (Duty $a, Duty $b): int => $a->getId() <=> $b->getId())) {
            throw new DutySwapNotApplicableException(DutySwapNotApplicableException::NOT_SWAPPABLE);
        }

        foreach ([['requester', $offeredBlock], ['counterpart', $counterpartBlock]] as [$party, $block]) {
            foreach ($block as $duty) {
                if ($duty->getStartsAt() <= $now) {
                    throw new DutySwapNotApplicableException(DutySwapNotApplicableException::DUTY_STARTED, $party);
                }
            }
        }

        $offeredCurrent = $this->assignmentRepository->findCurrentByGenerationAndDuties($generation, $offeredBlock);
        $counterpartCurrent = $this->assignmentRepository->findCurrentByGenerationAndDuties($generation, $counterpartBlock);
        $requesterMember = $request->getRequesterMember();
        $counterpartMember = $proposal->getCounterpartMember();
        if (
            ($offeredCurrent[(int) $offeredDuty->getId()] ?? null) !== $request->getOfferedAssignment()
            || $this->candidateService->currentBlockTeamMember($offeredBlock, $offeredCurrent) !== $requesterMember
        ) {
            throw new DutySwapNotApplicableException(DutySwapNotApplicableException::DUTY_CHANGED, 'requester');
        }
        if (
            ($counterpartCurrent[(int) $counterpartDuty->getId()] ?? null) !== $proposal->getCounterpartAssignment()
            || $this->candidateService->currentBlockTeamMember($counterpartBlock, $counterpartCurrent) !== $counterpartMember
        ) {
            throw new DutySwapNotApplicableException(DutySwapNotApplicableException::DUTY_CHANGED, 'counterpart');
        }

        foreach ([['requester', $offeredCurrent], ['counterpart', $counterpartCurrent]] as [$party, $rows]) {
            foreach ($rows as $row) {
                if ($row->isLocked()) {
                    throw new DutySwapNotApplicableException(DutySwapNotApplicableException::DUTY_LOCKED, $party);
                }
            }
        }

        foreach ([['requester', $offeredBlock], ['counterpart', $counterpartBlock]] as [$party, $block]) {
            try {
                $this->conditionalCoverage->assertCanBeNewlyCovered($block);
            } catch (CoverageNotRequiredException|CoverageUndeterminedException) {
                throw new DutySwapNotApplicableException(DutySwapNotApplicableException::COVERAGE_NOT_REQUIRED, $party);
            }
        }

        // The calendar AFTER the swap: each person is checked without the unit they hand over.
        $counterpartError = $this->candidateService->assignabilityError($generation, $offeredBlock, $counterpartMember, [], $counterpartBlock);
        if (null !== $counterpartError) {
            throw new DutySwapNotApplicableException($counterpartError, 'counterpart');
        }
        $requesterError = $this->candidateService->assignabilityError($generation, $counterpartBlock, $requesterMember, [], $offeredBlock);
        if (null !== $requesterError) {
            throw new DutySwapNotApplicableException($requesterError, 'requester');
        }

        $snapshot = $this->snapshotRepository->findOneByGeneration($generation)
            ?? throw new DutySwapNotApplicableException(DutySwapNotApplicableException::DUTY_CHANGED);

        return [$generation, $snapshot, $offeredBlock, $counterpartBlock, $offeredCurrent, $counterpartCurrent];
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
