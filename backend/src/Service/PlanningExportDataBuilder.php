<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\Clock\ClockInterface;

/**
 * Builds the export's intermediate model (docs/planning-export.md) from
 * CurrentCalendarReader — the very read behind the calendar screen's
 * "current" state (D143): DutyAssignment.current as it is *now*, so a
 * reassignment made after publication shows in the next export at once.
 * Never an OptimizationResult, a PlanningSnapshot, a generation's frozen
 * coverageStatus or a PlanningPublication's entries.
 *
 * A person reads exactly as on the calendar screen: the holder's
 * User first name + last name, through their PlanningTeamMember.
 */
final class PlanningExportDataBuilder
{
    public function __construct(
        private readonly CurrentCalendarReader $calendarReader,
        private readonly ClockInterface $clock,
    ) {
    }

    public function build(PlanningExportRequest $request): PlanningExportData
    {
        $columnByLineId = [];
        foreach ($request->lines as $column => $choice) {
            $columnByLineId[(int) $choice->line->getId()] = $column;
        }

        $fromKey = $request->from->format('Y-m-d');
        $toKey = $request->toExclusive->format('Y-m-d');

        /** @var array<string, array<int, list<CalendarCell>>> $cellsByDate */
        $cellsByDate = [];
        $selected = static fn ($line): bool => isset($columnByLineId[(int) $line->getId()]);
        foreach ($this->calendarReader->read($request->planning, $selected) as $cell) {
            $date = $cell->duty->getLocalDate()->format('Y-m-d');
            if ($date < $fromKey || $date >= $toKey) {
                continue;
            }
            $cellsByDate[$date][$columnByLineId[(int) $cell->line->getId()]][] = $cell;
        }

        $days = [];
        $last = $request->toExclusive->modify('-1 day');
        for ($date = $request->from; $date <= $last; $date = $date->modify('+1 day')) {
            $cells = [];
            foreach (array_keys($request->lines) as $column) {
                $calendarCells = $cellsByDate[$date->format('Y-m-d')][$column] ?? [];
                $showType = \count($calendarCells) > 1;
                $cells[] = array_map(static fn (CalendarCell $cell): PlanningExportItem => new PlanningExportItem(
                    null === $cell->member ? null : $cell->member->getUser()->getFirstName().' '.$cell->member->getUser()->getLastName(),
                    $showType ? $cell->duty->getDutyType()->getName() : null,
                ), $calendarCells);
            }
            $days[] = new PlanningExportDay($date, $cells);
        }

        return new PlanningExportData(
            $request->title,
            $request->format,
            $request->from,
            $last,
            $this->clock->now()->setTimezone(new \DateTimeZone($request->planning->getTimezone())),
            array_map(static fn (PlanningExportLineChoice $choice): PlanningExportLine => new PlanningExportLine((string) $choice->line->getStableId(), $choice->label), $request->lines),
            $days,
        );
    }
}
