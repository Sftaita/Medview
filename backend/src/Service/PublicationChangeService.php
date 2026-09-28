<?php

declare(strict_types=1);

namespace App\Service;

use App\Demand\LiveCoverageState;
use App\Entity\Planning;
use App\Entity\PlanningPublication;
use App\Entity\PlanningTeamMember;
use App\Entity\User;
use App\Repository\PlanningLineRepository;
use App\Repository\PlanningPublicationEntryRepository;
use App\Repository\PlanningTeamMemberRepository;

/**
 * "What changed since the last diffusion, and who must be told"
 * (docs/decisions.md D143).
 *
 * Changes: the current calendar compared duty by duty with the latest
 * PlanningPublication's frozen entries — by holder, never by
 * DutyAssignment row, so A → B → A is no change at all, and a block
 * changes on each of its days at once (its duties always move together).
 *
 * Audience of a republication — a business rule, not a convenience:
 * every person concerned by an impacted DATE is told, i.e.
 *   the previous holder(s) of each changed duty
 *   ∪ the new holder(s)
 *   ∪ whoever currently holds any duty, on any line, on any impacted date.
 * A changed block impacts every one of its dates. Deduplicated by user;
 * deactivated accounts are never emailed.
 */
final class PublicationChangeService
{
    public function __construct(
        private readonly PlanningPublicationEntryRepository $entryRepository,
        private readonly PlanningLineRepository $lineRepository,
        private readonly PlanningTeamMemberRepository $teamMemberRepository,
    ) {
    }

    /**
     * @param list<CalendarCell> $cells the current calendar (CurrentCalendarReader)
     *
     * @return list<PublicationChange> chronological
     */
    public function changesSince(PlanningPublication $reference, array $cells): array
    {
        $publishedByDutyId = [];
        foreach ($this->entryRepository->findByPublication($reference) as $entry) {
            $publishedByDutyId[(int) $entry->getDuty()->getId()] = $entry->getTeamMember();
        }

        $changes = [];
        foreach ($cells as $cell) {
            // A duty the reference never saw (a line added since) was never announced: it reads as "uncovered" before.
            // D166: except a conditional duty, which is absent from a publication only when it was not required then.
            $recorded = \array_key_exists((int) $cell->duty->getId(), $publishedByDutyId);
            $before = $publishedByDutyId[(int) $cell->duty->getId()] ?? null;
            if ($before?->getId() !== $cell->member?->getId()) {
                $changes[] = new PublicationChange($cell->line, $cell->duty, $before, $cell->member, $recorded || !$cell->duty->isConditional(), $cell->isShown());
            }
        }

        usort($changes, static fn (PublicationChange $a, PublicationChange $b): int => [$a->duty->getStartsAt(), $a->line->getPosition()] <=> [$b->duty->getStartsAt(), $b->line->getPosition()]);

        return $changes;
    }

    /**
     * @param list<PublicationChange> $changes
     * @param list<CalendarCell>      $cells   the current calendar, every line
     *
     * @return list<User>
     */
    public function audienceForChanges(array $changes, array $cells): array
    {
        $impactedDates = [];
        $members = [];
        foreach ($changes as $change) {
            $impactedDates[$change->duty->getLocalDate()->format('Y-m-d')] = true;
            $members[] = $change->before;
            $members[] = $change->after;
        }

        foreach ($cells as $cell) {
            if (isset($impactedDates[$cell->duty->getLocalDate()->format('Y-m-d')])) {
                $members[] = $cell->member;
            }
        }

        return $this->distinctActiveUsers($members);
    }

    /**
     * First publication: every participant of the planning — anyone whose
     * membership in an active line's team intersects the planning's range —
     * whether or not they hold a duty (the planning concerns them all).
     *
     * @return list<User>
     */
    public function audienceForFirstPublication(Planning $planning): array
    {
        $activeTeamIds = [];
        foreach ($this->lineRepository->findByPlanning($planning) as $line) {
            if ($line->isActive()) {
                $activeTeamIds[(int) $line->getPlanningTeam()->getId()] = true;
            }
        }

        $members = array_filter(
            $this->teamMemberRepository->findIntersectingForPlanning($planning, $planning->getStartsAt(), $planning->getEndsAt()),
            static fn (PlanningTeamMember $member): bool => isset($activeTeamIds[(int) $member->getPlanningTeam()->getId()]),
        );

        return $this->distinctActiveUsers(array_values($members));
    }

    /**
     * @param list<PlanningTeamMember|null> $members
     *
     * @return list<User> sorted by name
     */
    private function distinctActiveUsers(array $members): array
    {
        $users = [];
        foreach ($members as $member) {
            if (null === $member) {
                continue;
            }
            $user = $member->getUser();
            if ($user->isActive()) {
                $users[(int) $user->getId()] = $user;
            }
        }

        $users = array_values($users);
        usort($users, static fn (User $a, User $b): int => [$a->getLastName(), $a->getFirstName(), $a->getEmail()] <=> [$b->getLastName(), $b->getFirstName(), $b->getEmail()]);

        return $users;
    }

    /**
     * What a republication email says (docs/decisions.md D143):
     * - `units`: one line per changed unit — a whole block once, with its
     *   date range, never day by day — "before → after";
     * - `days`: for every impacted date, every line's situation that day,
     *   changed ("A → B") or not ("C — inchangé"), so a colleague on
     *   another line sees exactly why they are told.
     *
     * @param list<PublicationChange> $changes
     * @param list<CalendarCell>      $cells   the current calendar, every line
     *
     * @return array{units: list<array{line: string, when: string, block: ?string, before: string, after: string}>, days: list<array{label: string, rows: list<array{line: string, text: string}>}>}
     */
    public function digest(array $changes, array $cells): array
    {
        $units = [];
        $changeByDutyId = [];
        foreach ($changes as $change) {
            $changeByDutyId[(int) $change->duty->getId()] = $change;
            $group = $change->duty->getGroupInstance();
            $key = null !== $group ? 'g'.$group->getId() : 'd'.$change->duty->getId();
            $units[$key] ??= ['change' => $change, 'dates' => []];
            $units[$key]['dates'][] = $change->duty->getLocalDate();
        }

        $unitRows = [];
        foreach ($units as $unit) {
            $change = $unit['change'];
            $dates = $unit['dates'];
            sort($dates);
            $unitRows[] = [
                'line' => $change->line->getName(),
                'when' => FrenchDate::range($dates[0], $dates[\count($dates) - 1]),
                'block' => $change->duty->getGroupInstance()?->getPattern()->getName(),
                'before' => self::holder($change->before, $change->beforeShown),
                'after' => self::holder($change->after, $change->afterShown),
            ];
        }

        $impactedDates = [];
        foreach ($changes as $change) {
            $impactedDates[$change->duty->getLocalDate()->format('Y-m-d')] = $change->duty->getLocalDate();
        }
        ksort($impactedDates);

        $days = [];
        foreach ($impactedDates as $dateKey => $date) {
            $cellsOfDay = array_values(array_filter($cells, static fn (CalendarCell $cell): bool => $cell->duty->getLocalDate()->format('Y-m-d') === $dateKey));
            usort($cellsOfDay, static fn (CalendarCell $a, CalendarCell $b): int => [$a->line->getPosition(), $a->duty->getStartsAt()] <=> [$b->line->getPosition(), $b->duty->getStartsAt()]);

            $perLine = [];
            foreach ($cellsOfDay as $cell) {
                $perLine[(int) $cell->line->getId()][] = $cell;
            }

            $rows = [];
            foreach ($cellsOfDay as $cell) {
                $showType = \count($perLine[(int) $cell->line->getId()]) > 1;
                $change = $changeByDutyId[(int) $cell->duty->getId()] ?? null;
                if (null === $change && !$cell->isShown()) {
                    continue; // D166: a reinforcement nobody needs, unchanged — not part of the day's situation
                }
                $text = null !== $change
                    ? self::holder($change->before, $change->beforeShown).' → '.self::holder($change->after, $change->afterShown)
                    : self::unchangedText($cell);
                $rows[] = [
                    'line' => $cell->line->getName().($showType ? ' · '.$cell->duty->getDutyType()->getName() : ''),
                    'text' => $text,
                ];
            }

            $days[] = ['label' => ucfirst(FrenchDate::withWeekday($date)), 'rows' => $rows];
        }

        return ['units' => $unitRows, 'days' => $days];
    }

    /**
     * An unchanged cell of the day (docs/decisions.md D167): a reinforcement reads as its live state — "Renfort
     * requis — non attribué" when it is needed and missing (it may not even have existed at the last
     * diffusion), never a plain "Non attribué" that would look like nothing changed for it.
     */
    private static function unchangedText(CalendarCell $cell): string
    {
        if (null === $cell->member && LiveCoverageState::REQUIRED_UNASSIGNED === $cell->coverageState) {
            return 'Renfort requis — non attribué';
        }
        if (null === $cell->member && $cell->isUndetermined()) {
            return 'Renfort non évalué';
        }

        return self::holder($cell->member).' — inchangé';
    }

    /**
     * @param bool $shown false: a reinforcement nobody needs (D166) — "Pas de renfort", never "Non attribué"
     */
    private static function holder(?PlanningTeamMember $member, bool $shown = true): string
    {
        if (null !== $member) {
            return $member->getUser()->getFirstName().' '.$member->getUser()->getLastName();
        }

        return $shown ? 'Non attribué' : 'Pas de renfort';
    }
}
