<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Planning;
use App\Entity\PlanningCycle;
use App\Entity\PlanningGeneration;
use App\Entity\PlanningLine;
use App\Entity\PlanningTeamMember;
use App\Repository\DutyAssignmentRepository;

/**
 * Purely descriptive duty counts by ISO weekday (docs/decisions.md D132) —
 * never a judgment ("balanced"/"unbalanced", §45 of the spec). Read
 * directly from the live current `DutyAssignment` state (§5/§39: never a
 * stored, fragile historical counter) — a block's constituent Duties are
 * counted on their own real day, never as one unit (§6).
 *
 * Several planning periods (docs/decisions.md D201, lot L5 — D132's
 * "they coincide today" no longer holds): a line has one period per
 * planning period, read through CycleHistoryReader. "This period",
 * "published cumulative" and "provisional" are three distinct sets of line
 * periods counted by the very same rules — individual duties on their real
 * day, families by day, DutyType::workloadValue per duty (D147, D193: a
 * remainder block counts its real days only) — one group per line, one row
 * per membership (stint), never two lines merged. On a planning with a
 * single period, "this period" holds the same numbers as before.
 */
final class PlanningStatisticsService
{
    /** @var list<string> ISO weekday order, Monday first. */
    private const WEEKDAYS = ['MON', 'TUE', 'WED', 'THU', 'FRI', 'SAT', 'SUN'];

    public function __construct(
        private readonly CycleHistoryReader $historyReader,
        private readonly DutyAssignmentRepository $assignmentRepository,
    ) {
    }

    /**
     * The statistics of $cycle, the selected planning period (docs/decisions.md
     * D201). Every count is the same as before — current assignments of each
     * line period's most recent COMPLETED generation, duty by duty — only the
     * set of line periods summed differs from one scope to the other:
     *
     *  - selected period: each line's period in $cycle that has a generation;
     *  - published cumulative: the PUBLISHED line periods of periods 1 to
     *    $cycle — a line period is published by its own status, never because
     *    another line of the same period is;
     *  - provisional: the other line periods with a generation over the same
     *    periods, never added to the published cumulative.
     *
     * $withProvisional false (a reader who does not manage the calendar):
     * nothing unpublished at all — neither in the selected period nor as a
     * provisional scope, which is then null.
     */
    public function forPlanning(Planning $planning, PlanningCycle $cycle, bool $withProvisional = true): PlanningStatistics
    {
        $periodGroups = [];
        $lines = [];
        /** @var array<string, array{line: PlanningLine, published: list<CycleLinePeriod>, provisional: list<CycleLinePeriod>}> $byLine */
        $byLine = [];
        foreach ($this->historyReader->linePeriods($planning, $cycle) as $linePeriod) {
            $key = (string) $linePeriod->line->getStableId();
            $byLine[$key] ??= ['line' => $linePeriod->line, 'published' => [], 'provisional' => []];
            if ($linePeriod->cycle === $cycle) {
                $publication = match (true) {
                    $linePeriod->published => StatisticsGroup::PUBLISHED,
                    null !== $linePeriod->generation => StatisticsGroup::UNPUBLISHED,
                    default => StatisticsGroup::NOT_GENERATED,
                };
                $lines[] = ['line' => $key, 'name' => $linePeriod->line->getName(), 'publication' => $publication];
                // Never a fake empty-but-present group: a line period with nothing generated yet contributes no group
                // at all (mirrors PlanningResultLine's null-generation state).
                if (null !== $linePeriod->generation && ($linePeriod->published || $withProvisional)) {
                    $periodGroups[] = $this->group($linePeriod->line, [$linePeriod], $publication);
                }
            }
            if (null === $linePeriod->generation) {
                continue;
            }
            $byLine[$key][$linePeriod->published ? 'published' : 'provisional'][] = $linePeriod;
        }

        $cumulativeGroups = [];
        $provisionalGroups = [];
        foreach ($byLine as ['line' => $line, 'published' => $published, 'provisional' => $provisional]) {
            if ([] !== $published) {
                $cumulativeGroups[] = $this->group($line, $published, StatisticsGroup::PUBLISHED);
            }
            if ([] !== $provisional) {
                $provisionalGroups[] = $this->group($line, $provisional, StatisticsGroup::UNPUBLISHED);
            }
        }

        $first = $planning->getCycles()[0] ?? $cycle;

        return new PlanningStatistics(
            $this->scope($cycle->getStartsAt(), $cycle->getEndsAt(), $periodGroups, $cycle->getNumber()),
            $this->scope($first->getStartsAt(), $cycle->getEndsAt(), $cumulativeGroups, $cycle->getNumber()),
            $withProvisional ? $this->scope($first->getStartsAt(), $cycle->getEndsAt(), $provisionalGroups, $cycle->getNumber()) : null,
            $cycle,
            $lines,
        );
    }

    /**
     * @param list<StatisticsGroup> $groups
     */
    private function scope(\DateTimeImmutable $startsAt, \DateTimeImmutable $endsAt, array $groups, int $through): StatisticsScope
    {
        $numbers = [];
        foreach ($groups as $group) {
            array_push($numbers, ...$group->periodNumbers);
        }
        $numbers = array_values(array_unique($numbers));
        sort($numbers);

        return new StatisticsScope($startsAt, $endsAt, $groups, $numbers, $through);
    }

    /**
     * One line's group summing the given line periods — never two lines in
     * one group (a person holding duties on two lines has a row in each,
     * each counting that line's duties only).
     *
     * @param non-empty-list<CycleLinePeriod> $linePeriods each with its generation
     */
    private function group(PlanningLine $line, array $linePeriods, string $publication): StatisticsGroup
    {
        $generations = [];
        $numbers = [];
        foreach ($linePeriods as $linePeriod) {
            if (null !== $linePeriod->generation) {
                $generations[] = $linePeriod->generation;
            }
            $numbers[] = $linePeriod->cycle->getNumber();
        }
        sort($numbers);

        return new StatisticsGroup((string) $line->getStableId(), $line->getName(), $this->memberRowsFor($generations), $publication, array_values(array_unique($numbers)));
    }

    /**
     * @param list<PlanningGeneration> $generations one per line period summed
     *
     * @return list<StatisticsMemberRow>
     */
    private function memberRowsFor(array $generations): array
    {
        $assignments = $this->assignmentRepository->findForGenerations($generations);

        // The family columns of this group (docs/decisions.md D137): every
        // AllocationFamily name this generation's assignments actually used,
        // plus the empty-string key whenever at least one assignment has no
        // family — never a hardcoded, guessed or team-agnostic list.
        $familyNames = [];
        foreach ($assignments as $assignment) {
            $familyNames[$assignment->getDuty()->getAllocationFamily()?->getName() ?? ''] = true;
        }
        $familyKeys = array_keys($familyNames);

        /** @var array<int, array{member: PlanningTeamMember, weekday: array<string, int>, family: array<string, int>, dutyType: array<string, int>, weightedLoad: float}> $byMemberId */
        $byMemberId = [];
        foreach ($assignments as $assignment) {
            $member = $assignment->getTeamMember();
            $memberId = (int) $member->getId();
            if (!isset($byMemberId[$memberId])) {
                $byMemberId[$memberId] = [
                    'member' => $member,
                    'weekday' => array_fill_keys(self::WEEKDAYS, 0),
                    'family' => array_fill_keys($familyKeys, 0),
                    'dutyType' => [],
                    'weightedLoad' => 0.0,
                ];
            }
            // ISO-8601 weekday: 1 = Monday .. 7 = Sunday — each constituent Duty of a
            // block counts on its own real day (§6), never the block as a single unit.
            $weekday = self::WEEKDAYS[((int) $assignment->getDuty()->getLocalDate()->format('N')) - 1];
            ++$byMemberId[$memberId]['weekday'][$weekday];
            ++$byMemberId[$memberId]['family'][$assignment->getDuty()->getAllocationFamily()?->getName() ?? ''];
            // docs/decisions.md D147: the duty type's own real weight (DutyType::workloadValue), per
            // constituent Duty — the same "charge pondérée" the per-person summary already shows.
            $dutyType = $assignment->getDuty()->getDutyType();
            $byMemberId[$memberId]['dutyType'][$dutyType->getName()] = ($byMemberId[$memberId]['dutyType'][$dutyType->getName()] ?? 0) + 1;
            $byMemberId[$memberId]['weightedLoad'] += $dutyType->getWorkloadValue();
        }

        $rows = [];
        foreach ($byMemberId as $entry) {
            $user = $entry['member']->getUser();
            $rows[] = new StatisticsMemberRow(
                (string) $entry['member']->getStableId(),
                $user->getFirstName(),
                $user->getLastName(),
                $entry['weekday'],
                $entry['family'],
                array_sum($entry['weekday']),
                $entry['dutyType'],
                round($entry['weightedLoad'], 2),
            );
        }

        usort($rows, static fn (StatisticsMemberRow $a, StatisticsMemberRow $b): int => [$a->lastName, $a->firstName] <=> [$b->lastName, $b->firstName]);

        return $rows;
    }
}
