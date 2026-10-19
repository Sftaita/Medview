<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\PlanningPeriodStatus;
use App\Entity\PlanningPublication;
use App\Entity\PlanningTeamMember;
use App\Exception\NothingPublishedInExportRangeException;
use App\Repository\PlanningPublicationEntryRepository;
use App\Repository\PlanningPublicationRepository;
use Symfony\Component\Clock\ClockInterface;

/**
 * Builds the export's intermediate model (docs/planning-export.md), period
 * by period over the requested range (docs/decisions.md D200). For each
 * planning period the range touches, each chosen line is read through its
 * own period there:
 *
 *  - PUBLISHED: exported;
 *  - any other status: never exported — its days read "Non publiée" and it
 *    is listed in PlanningExportData::$excluded (never mixed silently with
 *    the published ones);
 *  - no period there: nothing to show, an empty cell.
 *
 * What a published line period shows depends on the requester:
 *
 *  - a manager (PlanningExportRequest::$operational): the *current*
 *    calendar (CurrentCalendarReader, DutyAssignment.current — D143), so a
 *    reassignment made after publication shows at once; compared with each
 *    period's latest publication (PublicationChangeService, by person, so
 *    A → B → A is no change) to label the document "Calendrier
 *    opérationnel — modifications non publiées possibles" when it differs;
 *  - anybody else: exactly what the period's latest PlanningPublication
 *    diffused — its append-only entries, holder by holder; a duty it did
 *    not diffuse is left out, a change made since is never shown.
 *
 * Never an OptimizationResult, a PlanningSnapshot or a generation's frozen
 * coverageStatus. A person reads exactly as on the calendar screen: the
 * holder's User first name + last name, through their PlanningTeamMember.
 */
final class PlanningExportDataBuilder
{
    public function __construct(
        private readonly CurrentCalendarReader $calendarReader,
        private readonly PlanningPublicationRepository $publicationRepository,
        private readonly PlanningPublicationEntryRepository $entryRepository,
        private readonly PublicationChangeService $changeService,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @throws NothingPublishedInExportRangeException
     */
    public function build(PlanningExportRequest $request): PlanningExportData
    {
        $planning = $request->planning;
        $timezone = new \DateTimeZone($planning->getTimezone());
        $fromKey = $request->from->format('Y-m-d');
        $toKey = $request->toExclusive->format('Y-m-d');
        $last = $request->toExclusive->modify('-1 day');
        $multiPeriod = \count($planning->getCycles()) > 1;

        /** @var array<string, array<int, list<array{member: ?PlanningTeamMember, undetermined: bool, dutyType: string}>>> $itemsByDate */
        $itemsByDate = [];
        /** @var array<string, list<int>> $unpublishedByDate */
        $unpublishedByDate = [];
        /** @var array<string, int> $periodByDate */
        $periodByDate = [];
        $periods = [];
        $excluded = [];
        $exported = false;
        $changed = false;
        /** @var array<int, \DateTimeImmutable> $diffusions period number → its latest publication */
        $diffusions = [];

        foreach ($planning->getCycles() as $cycle) {
            $cycleFrom = max($fromKey, $cycle->getStartsAt()->format('Y-m-d'));
            $cycleTo = min($toKey, $cycle->getEndsAt()->format('Y-m-d'));
            if ($cycleFrom >= $cycleTo) {
                continue;
            }
            $dates = $this->dates($cycleFrom, $cycleTo);
            $periods[] = ['number' => $cycle->getNumber(), 'first' => new \DateTimeImmutable($cycleFrom), 'last' => new \DateTimeImmutable(end($dates))];
            foreach ($dates as $date) {
                $periodByDate[$date] = $cycle->getNumber();
            }

            /** @var array<int, int> $publishedColumns line id → column */
            $publishedColumns = [];
            foreach ($request->lines as $column => $choice) {
                $status = $choice->line->periodIn($cycle)?->getStatus();
                if (null === $status) {
                    continue;
                }
                if (PlanningPeriodStatus::PUBLISHED === $status) {
                    $publishedColumns[(int) $choice->line->getId()] = $column;
                    continue;
                }
                $excluded[] = ($multiPeriod ? 'Période '.$cycle->getNumber().' — ' : '').$choice->label;
                foreach ($dates as $date) {
                    $unpublishedByDate[$date][] = $column;
                }
            }
            if ([] === $publishedColumns) {
                continue;
            }
            $exported = true;

            $cells = array_values(array_filter(
                $this->calendarReader->read($planning, static fn ($line): bool => isset($publishedColumns[(int) $line->getId()]), $cycle),
                static function (CalendarCell $cell) use ($cycleFrom, $cycleTo): bool {
                    $date = $cell->duty->getLocalDate()->format('Y-m-d');

                    return $date >= $cycleFrom && $date < $cycleTo;
                },
            ));
            $reference = $this->publicationRepository->findLatestForCycle($cycle);

            foreach ($this->holders($cells, $reference, $request->operational, $changed) as ['cell' => $cell, 'member' => $member, 'undetermined' => $undetermined]) {
                $itemsByDate[$cell->duty->getLocalDate()->format('Y-m-d')][$publishedColumns[(int) $cell->line->getId()]][] = [
                    'member' => $member,
                    'undetermined' => $undetermined,
                    'dutyType' => $cell->duty->getDutyType()->getName(),
                ];
            }
            if (!$request->operational && null !== $reference) {
                $diffusions[$cycle->getNumber()] = $reference->getPublishedAt()->setTimezone($timezone);
            }
        }

        if (!$exported) {
            throw new NothingPublishedInExportRangeException();
        }

        $days = [];
        for ($date = $request->from; $date <= $last; $date = $date->modify('+1 day')) {
            $key = $date->format('Y-m-d');
            $cells = [];
            foreach (array_keys($request->lines) as $column) {
                $items = $itemsByDate[$key][$column] ?? [];
                $showType = \count($items) > 1;
                $cells[] = array_map(static fn (array $item): PlanningExportItem => new PlanningExportItem(
                    null === $item['member'] ? null : $item['member']->getUser()->getFirstName().' '.$item['member']->getUser()->getLastName(),
                    $showType ? $item['dutyType'] : null,
                    $item['undetermined'],
                ), $items);
            }
            $days[] = new PlanningExportDay($date, $cells, $periodByDate[$key] ?? null, $unpublishedByDate[$key] ?? []);
        }

        [$nature, $label] = match (true) {
            !$request->operational => [PlanningExportData::PUBLISHED, $this->publishedLabel($diffusions, $multiPeriod)],
            $changed => [PlanningExportData::OPERATIONAL_CHANGED, PlanningExportData::OPERATIONAL_CHANGED_LABEL],
            default => [PlanningExportData::OPERATIONAL, PlanningExportData::OPERATIONAL_LABEL],
        };

        return new PlanningExportData(
            $request->title,
            $request->format,
            $request->from,
            $last,
            $this->clock->now()->setTimezone($timezone),
            array_map(static fn (PlanningExportLineChoice $choice): PlanningExportLine => new PlanningExportLine((string) $choice->line->getStableId(), $choice->label), $request->lines),
            $days,
            $nature,
            $label,
            $multiPeriod,
            $periods,
            array_values(array_unique($excluded)),
        );
    }

    /**
     * Who each duty shows, in calendar order: the current holder for a
     * manager (and whether that differs from the diffusion, into $changed),
     * the diffused one otherwise.
     *
     * @param list<CalendarCell> $cells
     *
     * @return list<array{cell: CalendarCell, member: ?PlanningTeamMember, undetermined: bool}>
     */
    private function holders(array $cells, ?PlanningPublication $reference, bool $operational, bool &$changed): array
    {
        $holders = [];
        if ($operational) {
            $changed = $changed || null === $reference || [] !== $this->changeService->changesSince($reference, $cells);
            foreach ($cells as $cell) {
                // docs/decisions.md D166: a reinforcement nobody needs and nobody holds is not exported at all.
                if ($cell->isShown()) {
                    $holders[] = ['cell' => $cell, 'member' => $cell->member, 'undetermined' => null === $cell->member && $cell->isUndetermined()];
                }
            }

            return $holders;
        }

        if (null === $reference) {
            return [];
        }
        $diffused = [];
        foreach ($this->entryRepository->findByPublication($reference) as $entry) {
            $diffused[(int) $entry->getDuty()->getId()] = $entry->getTeamMember();
        }
        foreach ($cells as $cell) {
            if (\array_key_exists((int) $cell->duty->getId(), $diffused)) {
                $holders[] = ['cell' => $cell, 'member' => $diffused[(int) $cell->duty->getId()], 'undetermined' => false];
            }
        }

        return $holders;
    }

    /**
     * "Planning diffusé — dernière diffusion le 12 octobre 2026", or, over
     * several periods, "… — dernière diffusion : période 1 le 12 octobre
     * 2026, période 2 le 3 novembre 2026".
     *
     * @param array<int, \DateTimeImmutable> $diffusions
     */
    private function publishedLabel(array $diffusions, bool $multiPeriod): string
    {
        ksort($diffusions);
        if (!$multiPeriod && 1 === \count($diffusions)) {
            return 'Planning diffusé — dernière diffusion le '.FrenchDate::long(reset($diffusions));
        }

        $parts = [];
        foreach ($diffusions as $number => $at) {
            $parts[] = 'période '.$number.' le '.FrenchDate::long($at);
        }

        return 'Planning diffusé — dernière diffusion : '.implode(', ', $parts);
    }

    /**
     * @return non-empty-list<string> every date of [$from, $to[
     */
    private function dates(string $from, string $to): array
    {
        $dates = [];
        for ($date = new \DateTimeImmutable($from); $date->format('Y-m-d') < $to; $date = $date->modify('+1 day')) {
            $dates[] = $date->format('Y-m-d');
        }

        return $dates;
    }
}
