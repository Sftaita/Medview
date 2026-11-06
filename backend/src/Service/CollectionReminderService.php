<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AvailabilityCollectionResponse;
use App\Entity\Duty;
use App\Entity\PlanningCycle;
use App\Entity\PlanningLine;
use App\Entity\PlanningPeriod;
use App\Entity\PlanningPeriodStatus;
use App\Entity\PlanningTeamMember;
use App\Entity\User;
use App\Repository\AvailabilityCollectionResponseRepository;
use App\Repository\DutyAssignmentRepository;
use App\Repository\DutyRepository;
use App\Repository\PeriodPreparationRepository;
use App\Repository\PlanningGenerationRepository;
use App\Repository\PlanningLineRepository;
use App\Repository\PlanningTeamMemberRepository;

/**
 * The availability reminders of the dashboard (docs/decisions.md D234): the
 * open collections the current user takes part in, kept only while the
 * user's unavailabilities can still change something — never a reminder of
 * a past event.
 *
 * A collection stays OPEN in the database until someone closes it (nothing
 * is closed here: this is a reading). It is shown when, from today on, a day
 * of its window falls in a line of its period that the user belongs to on
 * that day and that is still waiting for an assignment from the engine:
 *
 * - the line's period is not generated yet (DRAFT) — from its coverage start
 *   (D233). A line with nothing demanded in the period (D194, NO_DEMAND) is
 *   neutral once another line of the period is generated; in a period where
 *   nothing is generated yet it still counts — its weekly structure is
 *   simply not configured yet, and the collection is there for it;
 * - or the line is generated or published but an extension created duties
 *   that nobody holds yet (D226): « Compléter la période » will give them
 *   holders, reading the calendars.
 *
 * An unavailability declared after a generation is recorded and told, never
 * applied (D216): a generated or published line asks for nothing more. The
 * days already past never count — nobody is asked to fill in the past. The
 * other lines of the planning never hide a line still waiting.
 *
 * A collection whose announcement is still scheduled is never shown (D214).
 * A collection without period (before docs/planning-cycles.md) keeps the
 * rule it always had: shown for its remaining days.
 */
final class CollectionReminderService
{
    public function __construct(
        private readonly AvailabilityCollectionResponseRepository $responseRepository,
        private readonly PeriodPreparationRepository $preparationRepository,
        private readonly PlanningLineRepository $lineRepository,
        private readonly PlanningTeamMemberRepository $teamMemberRepository,
        private readonly PlanningGenerationRepository $generationRepository,
        private readonly DutyRepository $dutyRepository,
        private readonly DutyAssignmentRepository $assignmentRepository,
        private readonly LineApplicabilityService $applicability,
        private readonly PlanningClock $clock,
    ) {
    }

    /**
     * @return list<CollectionReminder> in window order
     */
    public function remindersFor(User $user): array
    {
        $reminders = [];
        foreach ($this->responseRepository->findActiveInOpenCollectionsForUser($user) as $response) {
            $reminder = $this->reminderOf($response, $user);
            if (null !== $reminder) {
                $reminders[] = $reminder;
            }
        }

        return $reminders;
    }

    private function reminderOf(AvailabilityCollectionResponse $response, User $user): ?CollectionReminder
    {
        $collection = $response->getCollection();
        $cycle = $collection->getCycle();
        if (null !== $cycle && true === $this->preparationRepository->findOneByCycle($cycle)?->isAnnouncementScheduled()) {
            return null;
        }

        $today = $this->clock->today($collection->getPlanning());
        $from = max($collection->getStartsAt(), $today);
        $end = $collection->getEndsAt();
        if ($from >= $end) {
            return null;
        }

        if (null === $cycle) {
            return new CollectionReminder($collection, $from, $end, [], CollectionReminder::NOT_GENERATED);
        }

        $pendingFrom = null;
        $pendingEnd = null;
        $lines = [];
        $newDatesOnly = true;
        $periods = [];
        foreach ($this->lineRepository->findByPlanning($collection->getPlanning()) as $line) {
            $period = $line->isActive() ? $line->periodIn($cycle) : null;
            if (null !== $period) {
                $periods[] = [$line, $period];
            }
        }
        // A neutral line (D194) only stops counting once the period's generation has started on another line.
        $periodGenerated = [] !== array_filter($periods, static fn (array $pair): bool => PlanningPeriodStatus::DRAFT !== $pair[1]->getStatus());

        foreach ($periods as [$line, $period]) {
            if (PlanningPeriodStatus::ARCHIVED === $period->getStatus()) {
                continue;
            }

            foreach ($this->stintsOf($line, $user, $from, $end) as $stint) {
                [$stintFrom, $stintEnd] = $this->clamp($stint, $line, $period, $from, $end);
                if ($stintFrom >= $stintEnd) {
                    continue;
                }
                $pending = $this->pendingDays($line, $period, $cycle, $periodGenerated, $stintFrom, $stintEnd);
                if (null === $pending) {
                    continue;
                }
                [$daysFrom, $daysEnd, $draft] = $pending;
                $pendingFrom = null === $pendingFrom || $daysFrom < $pendingFrom ? $daysFrom : $pendingFrom;
                $pendingEnd = null === $pendingEnd || $daysEnd > $pendingEnd ? $daysEnd : $pendingEnd;
                if (!\in_array($line->getName(), $lines, true)) {
                    $lines[] = $line->getName();
                }
                $newDatesOnly = $newDatesOnly && !$draft;
            }
        }

        if (null === $pendingFrom || null === $pendingEnd) {
            return null;
        }

        return new CollectionReminder($collection, $pendingFrom, $pendingEnd, $lines, $newDatesOnly ? CollectionReminder::NEW_DATES : CollectionReminder::NOT_GENERATED);
    }

    /**
     * @return list<PlanningTeamMember> the user's stints in the line's team that intersect [$from, $end)
     */
    private function stintsOf(PlanningLine $line, User $user, \DateTimeImmutable $from, \DateTimeImmutable $end): array
    {
        return array_values(array_filter(
            $this->teamMemberRepository->findIntersecting($line->getPlanningTeam(), $from, $end),
            static fn (PlanningTeamMember $member): bool => $member->getUser() === $user,
        ));
    }

    /**
     * The days [from, end) of $stint that fall in the line's period, from its coverage start (D233) and from $from.
     *
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable}
     */
    private function clamp(PlanningTeamMember $stint, PlanningLine $line, PlanningPeriod $period, \DateTimeImmutable $from, \DateTimeImmutable $end): array
    {
        $starts = [$from, $stint->getMembershipStart(), $period->getStartsAt()];
        if (null !== $line->getCoverageStartsOn()) {
            $starts[] = $line->getCoverageStartsOn();
        }
        $ends = [$end, $period->getEndsAt()];
        if (null !== $stint->getMembershipEnd()) {
            $ends[] = $stint->getMembershipEnd();
        }

        return [max($starts), min($ends)];
    }

    /**
     * The first and (exclusive) last day still waiting for the engine in [$from, $end), and whether the line is not
     * generated at all — null when nothing there waits for the user's availabilities.
     *
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable, 2: bool}|null
     */
    private function pendingDays(PlanningLine $line, PlanningPeriod $period, PlanningCycle $cycle, bool $periodGenerated, \DateTimeImmutable $from, \DateTimeImmutable $end): ?array
    {
        if (PlanningPeriodStatus::DRAFT === $period->getStatus()) {
            $neutral = $periodGenerated && LineApplicability::NO_DEMAND === $this->applicability->forLine($line, $cycle)->state;

            return $neutral ? null : [$from, $end, true];
        }

        // docs/decisions.md D226: the days an extension added, still without holder.
        $generation = $this->generationRepository->findMostRecentCompletedByPlanningPeriod($period);
        if (null === $generation) {
            return null;
        }
        $added = array_values(array_filter(
            $this->dutyRepository->findAddedByExtension($period),
            static fn (Duty $duty): bool => $duty->getLocalDate() >= $from && $duty->getLocalDate() < $end,
        ));
        $held = $this->assignmentRepository->findCurrentByGenerationAndDuties($generation, $added);
        $first = null;
        $last = null;
        foreach ($added as $duty) {
            if (isset($held[(int) $duty->getId()])) {
                continue;
            }
            $date = $duty->getLocalDate();
            $first = null === $first || $date < $first ? $date : $first;
            $last = null === $last || $date > $last ? $date : $last;
        }

        return null === $first || null === $last ? null : [$first, $last->modify('+1 day'), false];
    }
}
