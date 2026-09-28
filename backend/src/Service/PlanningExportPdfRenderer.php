<?php

declare(strict_types=1);

namespace App\Service;

use Dompdf\Dompdf;
use Dompdf\Options;
use Twig\Environment;

/**
 * The PDF export (docs/planning-export.md §PDF): one month per page, A4
 * landscape, a Monday-to-Sunday grid; each week is a row of day numbers
 * followed by one row per exported line (its export name, in the chosen
 * order) with the person on duty under each day. Title, period and the
 * date/time the document was produced sit on every page, so a printed copy
 * can be recognised as possibly outdated after a later change.
 *
 * Renders PlanningExportData only — it never reads the database. Distinct
 * from PlanningPdfRenderer, which prints a frozen PlanningPublication.
 */
final class PlanningExportPdfRenderer
{
    private const MONTHS = ['Janvier', 'Février', 'Mars', 'Avril', 'Mai', 'Juin', 'Juillet', 'Août', 'Septembre', 'Octobre', 'Novembre', 'Décembre'];

    public function __construct(private readonly Environment $twig)
    {
    }

    /**
     * Largest PDF produced, in table rows (see rowCount()). dompdf holds the
     * whole document in memory and its cost follows the rows it lays out,
     * not the number of duties: measured ≈ 37 MB + 0.25 MB and 0.018 to
     * 0.026 s per row (dev machine, long wrapping names) — a year of 6 lines
     * (448 rows) already exceeded PHP's default 128 MB, and 1 188 rows took
     * 22 s. 800 rows (e.g. 10 lines over a whole year) stays around 20 s and
     * 240 MB. Beyond that, the request is refused up front
     * (PlanningExportRequestParser) instead of failing half-way; the .xlsx
     * writer streams and has no such limit.
     */
    public const MAX_ROWS = 800;

    /** Headroom for MAX_ROWS: ≈ 240 MB and ≈ 20 s measured. */
    private const RENDER_MEMORY_LIMIT = '512M';
    private const RENDER_TIME_LIMIT_SECONDS = 90;

    /**
     * Rows the PDF grid will hold: for each month, each week holding an
     * exported date is one row of dates plus one row per line — exactly
     * what view() lays out.
     */
    public static function rowCount(\DateTimeImmutable $first, \DateTimeImmutable $last, int $lineCount): int
    {
        $weeks = 0;
        for ($monthStart = $first->modify('first day of this month'); $monthStart <= $last; $monthStart = $monthStart->modify('first day of next month')) {
            $from = max($first, $monthStart);
            $to = min($last, $monthStart->modify('last day of this month'));
            $weeks += intdiv((int) $from->modify('monday this week')->diff($to)->days, 7) + 1;
        }

        return $weeks * ($lineCount + 1);
    }

    /**
     * Memory and time limits are raised for the rest of the request (the
     * response is sent right after) and never lowered back: PHP refuses —
     * with a warning — to go below the memory already in use.
     */
    public function render(PlanningExportData $data): string
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

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($this->twig->render('pdf/planning_export.html.twig', $this->view($data)));
        $dompdf->setPaper('A4', 'landscape');
        $dompdf->addInfo('Title', $data->title);
        $dompdf->render();

        // "Page n / N" through the canvas: the CSS counter(pages) is always 0 in dompdf.
        $canvas = $dompdf->getCanvas();
        $font = $dompdf->getFontMetrics()->getFont('DejaVu Sans');
        $canvas->page_text($canvas->get_width() / 2 - 20, $canvas->get_height() - 28, 'Page {PAGE_NUM} / {PAGE_COUNT}', $font, 6.5, [0.447, 0.494, 0.549]);

        return (string) $dompdf->output();
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

    /**
     * The template's whole input — public so tests can check the content
     * itself, not only that some PDF bytes came out. Each month lists only
     * the weeks holding at least one exported date; a date of the month
     * outside the exported range keeps its number but shows no duty.
     *
     * @return array{title: string, periodLabel: string, generatedAtLabel: string, lines: list<string>, months: list<array{label: string, weeks: list<list<array{number: ?string, inRange: bool, weekend: bool, cells: list<list<array{name: ?string, dutyType: ?string}>>}>>}>}
     */
    public function view(PlanningExportData $data): array
    {
        /** @var array<string, PlanningExportDay> $dayByDate */
        $dayByDate = [];
        foreach ($data->days as $day) {
            $dayByDate[$day->date->format('Y-m-d')] = $day;
        }

        $months = [];
        $monthStart = $data->first->modify('first day of this month');
        while ($monthStart <= $data->last) {
            $monthKey = $monthStart->format('Y-m');
            $monthEnd = $monthStart->modify('last day of this month');
            $weeks = [];
            for ($weekStart = $monthStart->modify('monday this week'); $weekStart <= $monthEnd; $weekStart = $weekStart->modify('+7 days')) {
                $week = [];
                $hasExportedDay = false;
                for ($offset = 0; $offset < 7; ++$offset) {
                    $date = $weekStart->modify("+{$offset} days");
                    $inMonth = $date->format('Y-m') === $monthKey;
                    $day = $inMonth ? ($dayByDate[$date->format('Y-m-d')] ?? null) : null;
                    $hasExportedDay = $hasExportedDay || null !== $day;
                    $week[] = [
                        'number' => $inMonth ? $date->format('j') : null,
                        'inRange' => null !== $day,
                        'weekend' => $offset >= 5,
                        'cells' => null === $day ? [] : array_map(
                            static fn (array $items): array => array_map(
                                static fn (PlanningExportItem $item): array => ['name' => $item->personName, 'dutyType' => $item->dutyTypeName],
                                $items,
                            ),
                            $day->cells,
                        ),
                    ];
                }
                if ($hasExportedDay) {
                    $weeks[] = $week;
                }
            }

            $months[] = ['label' => self::MONTHS[(int) $monthStart->format('n') - 1].' '.$monthStart->format('Y'), 'weeks' => $weeks];
            $monthStart = $monthStart->modify('first day of next month');
        }

        return [
            'title' => $data->title,
            'periodLabel' => 'Du '.FrenchDate::long($data->first).' au '.FrenchDate::long($data->last),
            'generatedAtLabel' => FrenchDate::long($data->generatedAt).' à '.$data->generatedAt->format('H:i'),
            'lines' => array_map(static fn (PlanningExportLine $line): string => $line->label, $data->lines),
            'months' => $months,
        ];
    }
}
