<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Duty;
use App\Entity\DutyAssignment;
use App\Entity\DutyAssignmentSource;
use App\Entity\PlanningGeneration;
use App\Entity\PlanningSnapshot;
use App\Entity\PlanningTeamMember;
use App\Exception\DuplicateDutyAssignmentException;
use App\Exception\InvalidDutyAssignmentException;
use App\Exception\PlanningGenerationNotSnapshottedException;
use App\Repository\PlanningSnapshotMemberRepository;
use App\Repository\PlanningSnapshotRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Creates DutyAssignment rows. Deliberately narrow (docs/planning-generation.md
 * §18): checks the structural invariants a client could otherwise violate
 * trivially (wrong PlanningPeriod, wrong PlanningTeam, duplicate), but performs no
 * eligibility check at all (availability, spacing, fairness, rules) — that
 * is EligibilityService's job, a later lot.
 *
 * Two real, distinct write paths, never conflated (docs/decisions.md
 * D106):
 *
 * - `createManual()` (Lot 3) — one HTTP request, one assignment,
 *   `DutyAssignmentSource::MANUAL` always forced, flushes immediately (its
 *   own transaction).
 * - `createAuto()` (Lot 6E) — called many times in a loop by
 *   `PlanningGenerationService::generate()` while persisting a whole solve
 *   result; never flushes itself (the caller flushes exactly once for the
 *   entire batch + the generation's own metadata + status transitions, the
 *   atomicity §18 of the lot requires) and accepts an already-resolved
 *   `PlanningSnapshot` instead of looking it up again for every single
 *   assignment.
 */
final class DutyAssignmentService
{
    public function __construct(
        private readonly PlanningSnapshotRepository $snapshotRepository,
        private readonly PlanningSnapshotMemberRepository $snapshotMemberRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @throws PlanningGenerationNotSnapshottedException
     * @throws InvalidDutyAssignmentException
     * @throws DuplicateDutyAssignmentException
     */
    public function createManual(PlanningGeneration $generation, Duty $duty, PlanningTeamMember $teamMember, bool $locked): DutyAssignment
    {
        $snapshot = $this->snapshotRepository->findOneByGeneration($generation);
        if (null === $snapshot) {
            throw new PlanningGenerationNotSnapshottedException();
        }

        $assignment = $this->buildAssignment($generation, $snapshot, $duty, $teamMember, DutyAssignmentSource::MANUAL, $locked);
        $this->entityManager->persist($assignment);

        try {
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException) {
            // The absence of a prior findBy() check above is deliberate:
            // the unique constraint on (planning_generation_id, duty_id) is
            // the real guarantee, and catching its violation here turns a
            // concurrent duplicate into the same clean 409 a sequential one
            // would get (same pattern as D026/UserRegistrationService).
            throw new DuplicateDutyAssignmentException();
        }

        return $assignment;
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
