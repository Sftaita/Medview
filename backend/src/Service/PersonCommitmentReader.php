<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Planning;
use App\Entity\PlanningLine;
use App\Entity\PlanningPeriod;
use App\Entity\User;
use App\Repository\DutyAssignmentRepository;
use App\Repository\PlanningGenerationRepository;
use App\Repository\PlanningLineRepository;

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
        private readonly PlanningLineRepository $lineRepository,
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
     * What a person currently holds in the given periods of lines, whatever
     * their cycle (docs/planning-cycles.md §5.4, lot L3): each period's
     * current generation, published or not, and its current assignments.
     *
     * $planned (docs/decisions.md D191): for a period it holds, what the
     * launch in progress is about to write replaces the current calendar.
     *
     * @param list<PlanningPeriod> $periods periods of lines (an orphan period, without line, is ignored)
     *
     * @return list<PersonCommitment> chronological
     */
    public function forUserInPeriods(array $periods, User $user, ?PlannedCalendar $planned = null): array
    {
        return $this->readPeriods($periods, $user, $planned);
    }

    /**
     * @param list<PlanningPeriod> $periods
     *
     * @return array<string, list<PersonCommitment>> keyed by the holder's User stable id
     */
    public function byUserStableIdInPeriods(array $periods, ?PlannedCalendar $planned = null): array
    {
        $byUser = [];
        foreach ($this->readPeriods($periods, null, $planned) as $commitment) {
            $byUser[(string) $commitment->teamMember->getUser()->getStableId()][] = $commitment;
        }

        return $byUser;
    }

    /**
     * Every period of every active line of $planning, in every cycle — the
     * calendar a person's commitments are read from across periods.
     *
     * @return list<PlanningPeriod>
     */
    public function periodsOfActiveLines(Planning $planning): array
    {
        $periods = [];
        foreach ($this->lineRepository->findByPlanning($planning) as $line) {
            if ($line->isActive()) {
                array_push($periods, ...$line->getPeriods());
            }
        }

        return $periods;
    }

    /**
     * @param list<PlanningLine> $lines
     *
     * @return list<PersonCommitment>
     */
    private function read(array $lines, ?User $user): array
    {
        $periods = [];
        foreach ($lines as $line) {
            $period = $line->getSingleCyclePeriod();
            if (null !== $period) {
                $periods[] = $period;
            }
        }

        return $this->readPeriods($periods, $user);
    }

    /**
     * @param list<PlanningPeriod> $periods
     *
     * @return list<PersonCommitment>
     */
    private function readPeriods(array $periods, ?User $user, ?PlannedCalendar $planned = null): array
    {
        $commitments = [];
        foreach ($periods as $period) {
            if (null !== $planned && $planned->covers($period)) {
                foreach ($planned->commitmentsOf($period) as $commitment) {
                    if (null === $user || $commitment->teamMember->getUser() === $user) {
                        $commitments[] = $commitment;
                    }
                }
                continue;
            }

            $line = $period->getLine();
            $generation = null !== $line ? $this->generationRepository->findMostRecentCompletedByPlanningPeriod($period) : null;
            if (null === $line || null === $generation) {
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
