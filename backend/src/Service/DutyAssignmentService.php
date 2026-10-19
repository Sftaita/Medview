<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Duty;
use App\Entity\DutyAssignment;
use App\Entity\DutyAssignmentSource;
use App\Entity\PlanningGeneration;
use App\Entity\PlanningSnapshot;
use App\Entity\PlanningTeamMember;
use App\Exception\InvalidDutyAssignmentException;
use App\Repository\PlanningSnapshotMemberRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Creates DutyAssignment rows for the application's controlled write paths
 * only: each of them checks eligibility live and holds the CalendarWriteLock
 * itself. This service checks only the structural invariants a caller could
 * otherwise violate (the duty's PlanningPeriod is the generation's, the
 * member is of its PlanningTeam and in its snapshot), and never flushes: the
 * caller owns the transaction and the statement order (docs/decisions.md
 * D106, D131).
 *
 * - `createAuto()` (Lot 6E) — called many times in a loop by
 *   `PlanningGenerationService::generate()` while persisting a whole solve
 *   result (the caller flushes exactly once for the entire batch + the
 *   generation's own metadata + status transitions, the atomicity §18 of the
 *   lot requires), and by the completion.
 * - `createManualBatchItem()` / `createSwapBatchItem()` — a reassignment's /
 *   a swap's replacement rows (DutyReassignmentService).
 *
 * The former `createManual()` (lot 3) wrote one MANUAL assignment with no
 * eligibility check, no lock and no event, for the technical endpoint
 * `POST /api/planning-generations/{id}/assignments`. Both were removed
 * (docs/decisions.md D197).
 */
final class DutyAssignmentService
{
    public function __construct(
        private readonly PlanningSnapshotMemberRepository $snapshotMemberRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Never flushes — see class docblock. `$snapshot` must already be the
     * one belonging to `$generation` (the caller resolves it once for the
     * whole batch); not re-verified here beyond what `DutyAssignment`'s own
     * constructor already guards.
     *
     * @throws InvalidDutyAssignmentException
     */
    public function createAuto(PlanningGeneration $generation, PlanningSnapshot $snapshot, Duty $duty, PlanningTeamMember $teamMember): DutyAssignment
    {
        $assignment = $this->buildAssignment($generation, $snapshot, $duty, $teamMember, DutyAssignmentSource::AUTO, locked: false);
        $this->entityManager->persist($assignment);

        return $assignment;
    }

    /**
     * A manual reassignment's replacement row (docs/decisions.md D131) —
     * same shape as createAuto() (never flushes itself, accepts an
     * already-resolved snapshot), but forces MANUAL: an AUTO row replaced
     * by a person must never keep reading as AUTO. Used exclusively by
     * DutyReassignmentService, which controls the exact statement order a
     * partial-unique `current` index requires.
     *
     * @throws InvalidDutyAssignmentException
     */
    public function createManualBatchItem(PlanningGeneration $generation, PlanningSnapshot $snapshot, Duty $duty, PlanningTeamMember $teamMember, bool $locked = false): DutyAssignment
    {
        $assignment = $this->buildAssignment($generation, $snapshot, $duty, $teamMember, DutyAssignmentSource::MANUAL, $locked);
        $this->entityManager->persist($assignment);

        return $assignment;
    }

    /**
     * A swap's replacement row (docs/decisions.md D178) — same shape as
     * createManualBatchItem(), source SWAP: the row says it came from an
     * agreement between two members, never from a manager's edit. Used
     * exclusively by DutyReassignmentService::swap().
     *
     * @throws InvalidDutyAssignmentException
     */
    public function createSwapBatchItem(PlanningGeneration $generation, PlanningSnapshot $snapshot, Duty $duty, PlanningTeamMember $teamMember): DutyAssignment
    {
        $assignment = $this->buildAssignment($generation, $snapshot, $duty, $teamMember, DutyAssignmentSource::SWAP, locked: false);
        $this->entityManager->persist($assignment);

        return $assignment;
    }

    /**
     * @throws InvalidDutyAssignmentException
     */
    private function buildAssignment(PlanningGeneration $generation, PlanningSnapshot $snapshot, Duty $duty, PlanningTeamMember $teamMember, DutyAssignmentSource $source, bool $locked): DutyAssignment
    {
        if ($duty->getPlanningPeriod() !== $generation->getPlanningPeriod()) {
            throw new InvalidDutyAssignmentException('This Duty does not belong to the PlanningPeriod of this PlanningGeneration.');
        }

        if ($teamMember->getPlanningTeam() !== $generation->getPlanningPeriod()->getTeam()) {
            throw new InvalidDutyAssignmentException('This TeamMember does not belong to the PlanningTeam of this PlanningGeneration.');
        }

        $snapshotMember = $this->snapshotMemberRepository->findOneBySnapshotAndTeamMemberStableId($snapshot, $teamMember->getStableId());
        if (null === $snapshotMember) {
            throw new InvalidDutyAssignmentException('This TeamMember is not part of the snapshot used by this PlanningGeneration.');
        }

        return new DutyAssignment($generation, $duty, $teamMember, $snapshotMember, $source, $locked);
    }
}
