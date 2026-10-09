<?php

declare(strict_types=1);

namespace App\Service;

use Dompdf\Dompdf;
use Dompdf\Options;
use Twig\Environment;

/**
 * The absence PDF (docs/availability.md §11, docs/decisions.md D181): A4
 * landscape, first one Monday-to-Sunday calendar per month of the planning
 * with the name of everyone absent in each day, then "Récapitulatif des
 * absences par membre". Each person keeps one colour across the whole
 * document; the name is always written in full, so the colour only ever
 * helps — with more people than colours, colours repeat, names never do.
 *
 * Renders PlanningAbsenceExportData only — it never reads the database.
 */
final class PlanningAbsenceExportPdfRenderer
{
    private const MONTHS = ['Janvier', 'Février', 'Mars', 'Avril', 'Mai', 'Juin', 'Juillet', 'Août', 'Septembre', 'Octobre', 'Novembre', 'Décembre'];
    private const MONTHS_LOWER = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];

    /**
     * [mark, tint] pairs: a saturated mark for the left border, a pale tint
     * behind the name so dark text stays readable once printed.
     */
    private const PALETTE = [
        ['#2C7D5F', '#E3F1EB'],
        ['#2F6DB5', '#E2ECF7'],
        ['#B4531F', '#F8E8DE'],
        ['#7A4FB0', '#EEE6F7'],
        ['#B23A5B', '#F7E3E9'],
        ['#1F8A99', '#DFF1F3'],
        ['#8A6D12', '#F4EED9'],
        ['#4E6A1E', '#E9EFDD'],
        ['#A23B9E', '#F5E2F4'],
        ['#36579A', '#E3E8F3'],
        ['#C2410C', '#FBE7DC'],
        ['#55606B', '#E8EBEE'],
    ];

    /** Above this many people absent on one day of a month, that month's cells use a smaller font. */
    private const DENSE_FROM = 6;

    private const RENDER_MEMORY_LIMIT = '256M';
    private const RENDER_TIME_LIMIT_SECONDS = 60;

    public function __construct(private readonly Environment $twig)
    {
    }

    public function render(PlanningAbsenceExportData $data): string
    {
        $limit = (string) ini_get('memory_limit');
        if ('-1' !== $limit && self::bytes($limit) < self::bytes(self::RENDER_MEMORY_LIMIT)) {
            ini_set('memory_limit', self::RENDER_MEMORY_LIMIT);
        }
        $timeLimit = (int) ini_get('max_execution_time');
        if (0 !== $timeLimit && $timeLimit < self::RENDER_TIME_LIMIT_SECONDS) {
            set_time_limit(self::RENDER_TIME_LIMIT_SECONDS);
        }

        $options = new Options();
        $options->setDefaultFont('DejaVu Sans');
        $options->setIsRemoteEnabled(false);
        $options->setIsPhpEnabled(false);
        $options->setIsJavascriptEnabled(false);

        $view = $this->view($data);
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($this->twig->render('pdf/planning_absences.html.twig', $view));
        $dompdf->setPaper('A4', 'landscape');
        $dompdf->addInfo('Title', $view['documentTitle']);
        $dompdf->render();

        // "Page n / N" through the canvas: the CSS counter(pages) is always 0 in dompdf.
        $canvas = $dompdf->getCanvas();
        $font = $dompdf->getFontMetrics()->getFont('DejaVu Sans');
        $canvas->page_text($canvas->get_width() / 2 - 20, $canvas->get_height() - 28, 'Page {PAGE_NUM} / {PAGE_COUNT}', $font, 6.5, [0.447, 0.494, 0.549]);

        return (string) $dompdf->output();
    }

    /**
     * The template's whole input — public so tests check the content itself.
     *
     * @return array{documentTitle: string, title: string, periodLabel: string, generatedAtLabel: string, months: list<array<string, mixed>>, members: list<array<string, mixed>>}
     */
    public function view(PlanningAbsenceExportData $data): array
    {
        $withYear = substr($data->first, 0, 4) !== substr($data->last, 0, 4);

        $styles = [];
        $absentByDay = [];
        $members = [];
        foreach ($data->members as $index => $member) {
            [$mark, $tint] = self::PALETTE[$index % \count(self::PALETTE)];
            $style = ['name' => $member->displayName(), 'color' => $mark, 'tint' => $tint];
            $styles[$member->userStableId] = $style;
            foreach ($member->days as $day) {
                $absentByDay[$day][] = $member->userStableId;
            }

            $runs = $member->absenceRuns();
            $members[] = $style + [
                'lines' => [] === $member->lineNames ? '—' : implode(', ', $member->lineNames),
                'periods' => array_map(static fn (array $run): string => self::periodLabel($run[0], $run[1], $withYear), $runs),
                'totalLabel' => self::dayCount(\count($member->days)),
                'membershipLabel' => $member->membershipRuns === [[$data->first, $data->last]]
                    ? null
                    : 'Membre '.([] === $member->membershipRuns ? '—' : implode(', ', array_map(static fn (array $run): string => self::periodLabel($run[0], $run[1], $withYear), $member->membershipRuns))),
            ];
        }

        $months = [];
        for ($monthStart = substr($data->first, 0, 7).'-01'; $monthStart <= $data->last; $monthStart = (new \DateTimeImmutable($monthStart))->modify('first day of next month')->format('Y-m-d')) {
            $month = new \DateTimeImmutable($monthStart);
            $monthKey = $month->format('Y-m');
            $monthEnd = $month->modify('last day of this month')->format('Y-m-d');

            $weeks = [];
            $legend = [];
            $busiest = 0;
            for ($weekStart = $month->modify('monday this week')->format('Y-m-d'); $weekStart <= $monthEnd; $weekStart = AbsenceDays::addDays($weekStart, 7)) {
                $week = [];
                $hasActiveDay = false;
                for ($offset = 0; $offset < 7; ++$offset) {
                    $day = AbsenceDays::addDays($weekStart, $offset);
                    $inMonth = substr($day, 0, 7) === $monthKey;
                    $inRange = $inMonth && $day >= $data->first && $day <= $data->last;
                    $hasActiveDay = $hasActiveDay || $inRange;
                    $absentIds = $inRange ? ($absentByDay[$day] ?? []) : [];
                    foreach ($absentIds as $id) {
                        $legend[$id] = ($legend[$id] ?? 0) + 1;
                    }
                    $absent = array_map(static fn (string $id): array => $styles[$id], $absentIds);
                    $busiest = max($busiest, \count($absent));
                    $week[] = [
                        'number' => $inMonth ? (string) (int) substr($day, 8, 2) : null,
                        // The month is repeated on the first day of the month in each week: a week at the top of a
                        // continuation page stays readable on its own.
                        'monthTag' => $inMonth && (0 === $offset || '01' === substr($day, 8, 2)) ? self::MONTHS_LOWER[(int) $month->format('n') - 1] : null,
                        'inRange' => $inRange,
                        'weekend' => $offset >= 5,
                        'absent' => $absent,
                    ];
                }
                if ($hasActiveDay) {
                    $weeks[] = $week;
                }
            }

            // Legend in the document's member order (alphabetical), with the month's day count.
            $legendEntries = [];
            foreach ($styles as $id => $style) {
                if (isset($legend[$id])) {
                    $legendEntries[] = $style + ['daysLabel' => self::dayCount($legend[$id])];
                }
            }

            $months[] = [
                'label' => self::MONTHS[(int) $month->format('n') - 1].' '.$month->format('Y'),
                'weeks' => $weeks,
                'legend' => $legendEntries,
                'dense' => $busiest > self::DENSE_FROM,
            ];
        }

        $first = new \DateTimeImmutable($data->first);
        $last = new \DateTimeImmutable($data->last);

        return [
            'documentTitle' => 'Absences — '.$data->planningName,
            'title' => $data->planningName,
            'periodLabel' => 'Du '.FrenchDate::long($first).' au '.FrenchDate::long($last),
            'generatedAtLabel' => FrenchDate::long($data->generatedAt).' à '.$data->generatedAt->format('H:i'),
            'months' => $months,
            'members' => $members,
        ];
    }

    /**
     * "15 janvier", "15–18 janvier", "28 janvier – 3 février"; with
     * $withYear (a planning over two calendar years) the year is always
     * given; a run across two years always gives both years.
     */
    public static function periodLabel(string $first, string $last, bool $withYear): string
    {
        [$fy, $fm, $fd] = array_map('intval', explode('-', $first));
        [$ly, $lm, $ld] = array_map('intval', explode('-', $last));
        $day = static fn (int $d): string => 1 === $d ? '1er' : (string) $d;
        $month = static fn (int $m): string => self::MONTHS_LOWER[$m - 1];
        $year = static fn (int $y): string => $withYear ? ' '.$y : '';

        if ($first === $last) {
            return $day($fd).' '.$month($fm).$year($fy);
        }
        if ($fy !== $ly) {
            return $day($fd).' '.$month($fm).' '.$fy.' – '.$day($ld).' '.$month($lm).' '.$ly;
        }
        if ($fm === $lm) {
            return $day($fd).'–'.$day($ld).' '.$month($lm).$year($ly);
        }

        return $day($fd).' '.$month($fm).' – '.$day($ld).' '.$month($lm).$year($ly);
    }

    /** "0 jour", "1 jour", "9 jours". */
    public static function dayCount(int $days): string
    {
        return $days.' '.($days > 1 ? 'jours' : 'jour');
    }

    /** "128M" → bytes (PHP shorthand K/M/G). */
    private static function bytes(string $value): int
    {
        $number = (int) $value;

        return match (strtoupper(substr(trim($value), -1))) {
            'G' => $number * 1024 ** 3,
            'M' => $number * 1024 ** 2,
            'K' => $number * 1024,
            default => $number,
        };
    }
}
