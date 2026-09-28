<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\PlanningLine;
use App\Entity\User;
use App\Repository\DutyAssignmentRepository;
use App\Repository\PlanningGenerationRepository;

/**
 * What a person currently holds across the lines of a Planning
 * (docs/decisions.md D161): for each given line, its current generation
 * (the most recent COMPLETED one, D125) and its current assignments
 * (`DutyAssignment.current`, D131) — the same "current calendar" every
 * other reader uses.
 *
 * By User, never by PlanningTeamMember: since D160 the same person has a
 * different stint on each line they belong to, and it is the person who
 * cannot be in two places at once.
 */
final class PersonCommitmentReader
{
    public function __construct(
        private readonly PlanningGenerationRepository $generationRepository,
        private readonly DutyAssignmentRepository $assignmentRepository,
    ) {
    }

    /**
     * @param list<PlanningLine> $lines
     *
     * @return list<PersonCommitment> chronological
     */
    public function forUser(array $lines, User $user): array
    {
        return $this->read($lines, $user);
    }

    /**
     * Every current commitment on $lines, grouped by the holder's User
     * stable id.
     *
     * @param list<PlanningLine> $lines
     *
     * @return array<string, list<PersonCommitment>>
     */
    public function byUserStableId(array $lines): array
    {
        $byUser = [];
        foreach ($this->read($lines, null) as $commitment) {
            $byUser[(string) $commitment->teamMember->getUser()->getStableId()][] = $commitment;
        }

        return $byUser;
    }

    /**
     * @param list<PlanningLine> $lines
     *
     * @return list<PersonCommitment>
     */
    private function read(array $lines, ?User $user): array
    {
        $commitments = [];
        foreach ($lines as $line) {
            $generation = $this->generationRepository->findMostRecentCompletedByPlanningPeriod($line->getPlanningPeriod());
            if (null === $generation) {
                continue;
            }

            foreach ($this->assignmentRepository->findForGenerations([$generation], $user) as $assignment) {
                $commitments[] = new PersonCommitment($line, $generation, $assignment->getDuty(), $assignment->getTeamMember());
            }
        }

        usort($commitments, static fn (PersonCommitment $a, PersonCommitment $b): int => [$a->duty->getStartsAt(), (string) $a->duty->getStableId()] <=> [$b->duty->getStartsAt(), (string) $b->duty->getStableId()]);

        return $commitments;
    }
}
