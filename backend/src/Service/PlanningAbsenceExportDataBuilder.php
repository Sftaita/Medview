<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Planning;
use App\Entity\PlanningLine;
use App\Entity\PlanningTeamMember;
use App\Entity\User;
use App\Entity\UserAvailabilityType;
use App\Repository\PlanningLineRepository;
use App\Repository\PlanningTeamMemberRepository;
use App\Repository\UserAvailabilityPeriodRepository;
use Symfony\Component\Clock\ClockInterface;

/**
 * The data of "Exporter les absences (PDF)" (docs/availability.md §11,
 * docs/decisions.md D181): who takes part in the planning, and on which
 * civil days each of them declared an UNAVAILABLE period — read live from
 * the personal calendars, never from a generation snapshot, never written.
 *
 * Scope, in order:
 * 1. the planning period [startsAt, endsAt[ (dates, endsAt exclusive),
 *    extensions included since they move these very fields (D122);
 * 2. the participants: every membership of the planning intersecting that
 *    period, one entry per User whatever their number of stints or lines;
 * 3. their UNAVAILABLE periods (never PREFER_DUTY, never the administrative
 *    non-participation periods) intersecting it — one query for everyone;
 * 4. each period turned into the civil days it touches in the planning's
 *    timezone (AbsenceDays::touchedDays), then kept only on days within the
 *    planning period *and* within one of the person's memberships: the
 *    export never shows someone's calendar for days they were not part of
 *    the planning. Nothing stored is clipped or merged — only these days.
 */
final class PlanningAbsenceExportDataBuilder
{
    public function __construct(
        private readonly PlanningTeamMemberRepository $teamMemberRepository,
        private readonly PlanningLineRepository $lineRepository,
        private readonly UserAvailabilityPeriodRepository $availabilityPeriodRepository,
        private readonly ClockInterface $clock,
    ) {
    }

    public function build(Planning $planning): PlanningAbsenceExportData
    {
        $timezone = new \DateTimeZone($planning->getTimezone());
        $first = $planning->getStartsAt()->format('Y-m-d');
        $last = AbsenceDays::addDays($planning->getEndsAt()->format('Y-m-d'), -1);

        $lineByTeamId = [];
        foreach ($this->lineRepository->findByPlanning($planning) as $line) {
            $lineByTeamId[$line->getPlanningTeam()->getId()] = $line;
        }

        /** @var array<int, array{user: User, stints: list<PlanningTeamMember>}> $byUser */
        $byUser = [];
        foreach ($this->teamMemberRepository->findIntersectingForPlanning($planning, $planning->getStartsAt(), $planning->getEndsAt()) as $member) {
            $user = $member->getUser();
            $byUser[$user->getId()] ??= ['user' => $user, 'stints' => []];
            $byUser[$user->getId()]['stints'][] = $member;
        }

        $daysByUser = [];
        $periods = $this->availabilityPeriodRepository->findByTypeIntersectingForUsers(
            array_values(array_map(static fn (array $entry): User => $entry['user'], $byUser)),
            UserAvailabilityType::UNAVAILABLE,
            AbsenceDays::startOfDay($first, $timezone),
            AbsenceDays::startOfDay($planning->getEndsAt()->format('Y-m-d'), $timezone),
        );
        foreach ($periods as $period) {
            [$from, $to] = AbsenceDays::touchedDays($period->getStartsAt(), $period->getEndsAt(), $timezone);
            $clipped = AbsenceDays::clip($from, $to, $first, $last);
            if (null === $clipped) {
                continue;
            }
            foreach (AbsenceDays::each($clipped[0], $clipped[1]) as $day) {
                $daysByUser[$period->getUser()->getId()][$day] = true;
            }
        }

        $members = [];
        foreach ($byUser as $userId => ['user' => $user, 'stints' => $stints]) {
            $memberDays = [];
            foreach ($stints as $stint) {
                $stintLast = null === $stint->getMembershipEnd() ? $last : AbsenceDays::addDays($stint->getMembershipEnd()->format('Y-m-d'), -1);
                $clipped = AbsenceDays::clip($stint->getMembershipStart()->format('Y-m-d'), $stintLast, $first, $last);
                foreach (null === $clipped ? [] : AbsenceDays::each($clipped[0], $clipped[1]) as $day) {
                    $memberDays[$day] = true;
                }
            }
            ksort($memberDays);

            $days = array_keys(array_intersect_key($daysByUser[$userId] ?? [], $memberDays));
            sort($days);

            $members[] = new PlanningAbsenceExportMember(
                (string) $user->getStableId(),
                $user->getFirstName(),
                $user->getLastName(),
                $this->lineNames($stints, $lineByTeamId),
                $days,
                AbsenceDays::runs(array_keys($memberDays)),
            );
        }

        $collator = new \Collator('fr_FR');
        usort($members, static fn (PlanningAbsenceExportMember $a, PlanningAbsenceExportMember $b): int => $collator->compare($a->lastName, $b->lastName)
            ?: $collator->compare($a->firstName, $b->firstName)
            ?: $a->userStableId <=> $b->userStableId);

        return new PlanningAbsenceExportData(
            $planning->getName(),
            $first,
            $last,
            $this->clock->now()->setTimezone($timezone),
            $members,
        );
    }

    /**
     * The distinct lines of the person's stints, in the planning's line order.
     *
     * @param list<PlanningTeamMember> $stints
     * @param array<int, PlanningLine> $lineByTeamId
     *
     * @return list<string>
     */
    private function lineNames(array $stints, array $lineByTeamId): array
    {
        $lines = [];
        foreach ($stints as $stint) {
            $line = $lineByTeamId[$stint->getPlanningTeam()->getId()] ?? null;
            if (null !== $line) {
                $lines[$line->getId()] = $line;
            }
        }
        usort($lines, static fn (PlanningLine $a, PlanningLine $b): int => [$a->getPosition(), $a->getId()] <=> [$b->getPosition(), $b->getId()]);

        return array_map(static fn (PlanningLine $line): string => $line->getName(), $lines);
    }
}
