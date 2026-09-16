<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Duty;
use App\Entity\DutyAssignment;
use App\Entity\DutyAssignmentSource;
use App\Entity\PlanningGeneration;
use App\Entity\TeamMember;
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
 * trivially (wrong PlanningPeriod, wrong Team, duplicate), but performs no
 * eligibility check at all (availability, spacing, fairness, rules) — that
 * is EligibilityService's job, a later lot. Every assignment created here
 * is forced to DutyAssignmentSource::MANUAL; the client never chooses.
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
    public function createManual(PlanningGeneration $generation, Duty $duty, TeamMember $teamMember, bool $locked): DutyAssignment
    {
        $snapshot = $this->snapshotRepository->findOneByGeneration($generation);
        if (null === $snapshot) {
            throw new PlanningGenerationNotSnapshottedException();
        }

        if ($duty->getPlanningPeriod() !== $generation->getPlanningPeriod()) {
            throw new InvalidDutyAssignmentException('This Duty does not belong to the PlanningPeriod of this PlanningGeneration.');
        }

        if ($teamMember->getTeam() !== $generation->getPlanningPeriod()->getTeam()) {
            throw new InvalidDutyAssignmentException('This TeamMember does not belong to the Team of this PlanningGeneration.');
        }

        $snapshotMember = $this->snapshotMemberRepository->findOneBySnapshotAndTeamMemberStableId($snapshot, $teamMember->getStableId());
        if (null === $snapshotMember) {
            throw new InvalidDutyAssignmentException('This TeamMember is not part of the snapshot used by this PlanningGeneration.');
        }

        $assignment = new DutyAssignment($generation, $duty, $teamMember, $snapshotMember, DutyAssignmentSource::MANUAL, $locked);
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
}
