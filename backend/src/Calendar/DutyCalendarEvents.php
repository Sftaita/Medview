<?php

declare(strict_types=1);

namespace App\Calendar;

/**
 * "Mes gardes" units (MyDutiesService::dutiesOf(), D168) as all-day
 * calendar events (docs/decisions.md D170) — pure, no I/O.
 *
 * All-day, because duties are materialized midnight to midnight in the
 * planning's timezone (no configurable duty hours yet): a date is the honest
 * unit, a timed event would claim hours nobody configured. One event per
 * unit (a block once), split only if its days are not contiguous. The UID
 * derives from the unit's stable id: a reassigned duty leaves one person's
 * feed and enters another's, never duplicated in either.
 *
 * A reinforcement that is not currently required or still undetermined
 * (D165) is TENTATIVE, with the same wording as the "Mes gardes" badge.
 */
final class DutyCalendarEvents
{
    /**
     * @param list<array{
     *     key: string,
     *     planningStableId: string,
     *     planningName: string,
     *     lineName: string,
     *     dutyTypeName: string,
     *     blockName: string|null,
     *     dates: non-empty-list<string>,
     *     conditional: bool,
     *     coverageState: string|null,
     * }> $duties
     *
     * @return list<IcsEvent>
     */
    public static function fromDuties(array $duties, string $frontendUrl): array
    {
        $events = [];
        foreach ($duties as $duty) {
            $state = $duty['conditional'] ? $duty['coverageState'] : null;
            foreach (self::contiguousRuns($duty['dates']) as $i => $run) {
                $events[] = new IcsEvent(
                    uid: $duty['key'].(0 === $i ? '' : '-'.$run[0]).'@medvue',
                    firstDay: new \DateTimeImmutable($run[0]),
                    lastDay: new \DateTimeImmutable($run[\count($run) - 1]),
                    summary: self::summary($duty, $state),
                    description: self::description($duty, $state),
                    tentative: \in_array($state, ['UNDETERMINED', 'NOT_REQUIRED_ASSIGNED'], true),
                    url: rtrim($frontendUrl, '/').'/plannings/'.$duty['planningStableId'],
                );
            }
        }

        return $events;
    }

    /**
     * @param array{conditional: bool, lineName: string, blockName: string|null} $duty
     */
    private static function summary(array $duty, ?string $state): string
    {
        $summary = ($duty['conditional'] ? 'Renfort' : 'Garde').' '.$duty['lineName'];
        if (null !== $duty['blockName']) {
            $summary .= ' · '.$duty['blockName'];
        }

        return $summary.match ($state) {
            'UNDETERMINED' => ' (à confirmer)',
            'NOT_REQUIRED_ASSIGNED' => ' (non requis actuellement)',
            default => '',
        };
    }

    /**
     * @param array{planningName: string, lineName: string, dutyTypeName: string, blockName: string|null, conditional: bool} $duty
     */
    private static function description(array $duty, ?string $state): string
    {
        $lines = [
            'Planning : '.$duty['planningName'],
            'Ligne : '.$duty['lineName'],
            null !== $duty['blockName'] ? 'Bloc : '.$duty['blockName'] : 'Type : '.$duty['dutyTypeName'],
        ];
        if ($duty['conditional']) {
            $lines[] = match ($state) {
                'UNDETERMINED' => 'Renfort à confirmer : il dépend de la garde de la ligne source.',
                'NOT_REQUIRED_ASSIGNED' => 'Renfort non requis actuellement : vous restez inscrit tant que la garde n\'est pas retirée.',
                default => 'Renfort requis.',
            };
        }
        $lines[] = 'Agenda en lecture seule : les changements se font dans MedVue.';

        return implode("\n", $lines);
    }

    /**
     * @param non-empty-list<string> $dates sorted "Y-m-d"
     *
     * @return non-empty-list<non-empty-list<string>>
     */
    private static function contiguousRuns(array $dates): array
    {
        $runs = [[$dates[0]]];
        for ($i = 1, $n = \count($dates); $i < $n; ++$i) {
            $last = \count($runs) - 1;
            $dayAfter = (new \DateTimeImmutable($runs[$last][\count($runs[$last]) - 1]))->modify('+1 day')->format('Y-m-d');
            if ($dayAfter === $dates[$i]) {
                $runs[$last][] = $dates[$i];
            } else {
                $runs[] = [$dates[$i]];
            }
        }

        return $runs;
    }
}
