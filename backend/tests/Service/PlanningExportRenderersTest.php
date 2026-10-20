<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\PlanningExportData;
use App\Service\PlanningExportDay;
use App\Service\PlanningExportFormat;
use App\Service\PlanningExportItem;
use App\Service\PlanningExportLine;
use App\Service\PlanningExportPdfRenderer;
use App\Service\PlanningExportService;
use App\Service\PlanningExportXlsxRenderer;
use App\Tests\PlanningExportTestHelpers;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Both export renderers (docs/planning-export.md) consume the same
 * PlanningExportData and never read the database: these tests build that
 * model by hand and check what each file really contains.
 */
final class PlanningExportRenderersTest extends KernelTestCase
{
    use PlanningExportTestHelpers;

    private const LONG_NAME = 'Marie-Élisabeth de La Rochefoucauld-Montmorency';

    // --- PDF ---------------------------------------------------------------------------

    public function testThePdfHasOneMonthPerPageWithEveryWeekAndTheLinesInTheChosenOrder(): void
    {
        $data = $this->data('2026-10-01', '2026-12-31', ['Orthopédie', 'Membres supérieurs']);
        $renderer = static::getContainer()->get(PlanningExportPdfRenderer::class);
        $view = $renderer->view($data);

        self::assertSame(['Octobre 2026', 'Novembre 2026', 'Décembre 2026'], array_column($view['months'], 'label'));
        self::assertSame(['Orthopédie', 'Membres supérieurs'], $view['lines']);
        self::assertSame('Du 1er octobre 2026 au 31 décembre 2026', $view['periodLabel']);
        self::assertSame('28 septembre 2026 à 14:05', $view['generatedAtLabel']);

        $october = $view['months'][0];
        self::assertCount(5, $october['weeks'], 'Weeks of 28 Sept, 5, 12, 19 and 26 Oct.');
        $firstWeek = $october['weeks'][0];
        self::assertNull($firstWeek[0]['number'], 'Monday 28 September belongs to another month.');
        self::assertFalse($firstWeek[0]['inRange']);
        self::assertSame('1', $firstWeek[3]['number'], 'Thursday 1 October.');
        self::assertTrue($firstWeek[5]['weekend']);
        self::assertSame([['name' => 'Alice Martin', 'dutyType' => null, 'gap' => null]], $firstWeek[3]['cells'][0]);
        self::assertSame([['name' => self::LONG_NAME, 'dutyType' => null, 'gap' => null]], $firstWeek[3]['cells'][1], 'A long name is kept whole, never truncated.');
        self::assertSame([], $firstWeek[4]['cells'][1], 'No duty that day on that line.');
        self::assertSame([['name' => null, 'dutyType' => 'Jour', 'gap' => 'Non attribué'], ['name' => 'Bob Durand', 'dutyType' => 'Nuit', 'gap' => null]], $firstWeek[5]['cells'][0], 'Several duties: each with its type; uncovered = null, labelled "Non attribué" as before (D166).');

        $pdf = $renderer->render($data);
        self::assertStringStartsWith('%PDF-', $pdf);
        self::assertSame(3, $this->pdfPageCount($pdf), 'One month per page.');
    }

    public function testACustomPeriodInsideAMonthKeepsTheMonthGridButOnlyTheExportedDates(): void
    {
        $view = static::getContainer()->get(PlanningExportPdfRenderer::class)->view($this->data('2026-10-14', '2026-10-16', ['Ortho']));

        self::assertSame(['Octobre 2026'], array_column($view['months'], 'label'));
        self::assertCount(1, $view['months'][0]['weeks'], 'Only the week holding exported dates.');
        $week = $view['months'][0]['weeks'][0];
        self::assertSame('12', $week[0]['number']);
        self::assertFalse($week[0]['inRange'], 'The 12th is outside the exported range.');
        self::assertTrue($week[2]['inRange'], 'The 14th is exported.');
        self::assertFalse($week[5]['inRange'], 'The 17th is outside the exported range.');
    }

    public function testSeveralPeriodsAnUnpublishedOneAndUnpublishedChangesAreSpelledOutInBothFormats(): void
    {
        // docs/decisions.md D200: 29 April → 2 May 2027 across periods 1 and 2; period 2 of "Juniors" is a draft.
        $days = [];
        foreach (['2027-04-29' => 1, '2027-04-30' => 1, '2027-05-01' => 2, '2027-05-02' => 2] as $date => $period) {
            $days[] = new PlanningExportDay(new \DateTimeImmutable($date), [[new PlanningExportItem('Alice Martin', null)], []], $period, 2 === $period ? [1] : []);
        }
        $data = new PlanningExportData(
            'Gardes',
            PlanningExportFormat::PDF,
            new \DateTimeImmutable('2027-04-29'),
            new \DateTimeImmutable('2027-05-02'),
            new \DateTimeImmutable('2026-12-10 09:00'),
            [new PlanningExportLine('l1', 'Seniors'), new PlanningExportLine('l2', 'Juniors')],
            $days,
            PlanningExportData::OPERATIONAL_CHANGED,
            PlanningExportData::OPERATIONAL_CHANGED_LABEL,
            true,
            [
                ['number' => 1, 'first' => new \DateTimeImmutable('2027-04-29'), 'last' => new \DateTimeImmutable('2027-04-30')],
                ['number' => 2, 'first' => new \DateTimeImmutable('2027-05-01'), 'last' => new \DateTimeImmutable('2027-05-02')],
            ],
            ['Période 2 — Juniors'],
        );

        $renderer = static::getContainer()->get(PlanningExportPdfRenderer::class);
        $view = $renderer->view($data);
        self::assertSame('Calendrier opérationnel — modifications non publiées possibles', $view['headingLabel']);
        self::assertTrue($view['warning']);
        self::assertSame('Période 1 (du 29 avril 2027 au 30 avril 2027) · Période 2 (du 1er mai 2027 au 2 mai 2027)', $view['periodsLabel']);
        self::assertSame('Non inclus car non publiés : Période 2 — Juniors', $view['excludedLabel']);
        $april = $view['months'][0]['weeks'][0];
        self::assertSame(['P1', null], array_column(\array_slice($april, 3, 2), 'periodTag'), 'a tag only where a period starts in the export');
        $may = $view['months'][1]['weeks'][0];
        self::assertSame('P2', $may[5]['periodTag'], 'Saturday 1 May opens period 2');
        self::assertSame([1], $may[5]['unpublished']);
        self::assertSame([], $april[3]['unpublished']);
        $html = static::getContainer()->get('twig')->render('pdf/planning_export.html.twig', $view);
        self::assertSame(2, substr_count($html, 'Non publiée'), 'Juniors on 1 and 2 May — never "—"');
        self::assertStringContainsString('class="nature warning"', $html);
        self::assertStringStartsWith('%PDF-', $renderer->render($data));

        $sheets = $this->readXlsx(static::getContainer()->get(PlanningExportXlsxRenderer::class)->render($data));
        self::assertSame(['Date', 'Jour', 'Période', 'Seniors', 'Juniors'], $sheets['Planning'][0]);
        self::assertSame(['Période 1', 'Alice Martin', ''], [$sheets['Planning'][1][2], $sheets['Planning'][1][3], $sheets['Planning'][1][4] ?? '']);
        self::assertSame(['Période 2', 'Alice Martin', 'Non publiée'], \array_slice($sheets['Planning'][3], 2));
        self::assertSame(['Personne', 'Date', 'Jour', 'Ligne', 'Période'], $sheets['Par personne'][0]);
        self::assertContains(['Nature', 'Calendrier opérationnel — modifications non publiées possibles'], $sheets['Informations']);
        self::assertContains(['Non inclus car non publiés', 'Période 2 — Juniors'], $sheets['Informations']);

        // A current calendar identical to the diffusion: nothing more in the heading, as before.
        $plain = $renderer->view($this->data('2026-10-14', '2026-10-16', ['Ortho']));
        self::assertNull($plain['headingLabel']);
        self::assertNull($plain['periodsLabel']);
        self::assertNull($plain['excludedLabel']);
    }

    public function testTheSizeLimitCountsExactlyTheRowsThePdfLaysOut(): void
    {
        $renderer = static::getContainer()->get(PlanningExportPdfRenderer::class);
        foreach ([['2026-10-01', '2026-12-31', 2], ['2026-10-14', '2026-10-16', 1], ['2027-03-27', '2027-04-02', 3], ['2026-11-30', '2027-02-28', 5]] as [$from, $last, $lineCount]) {
            $view = $renderer->view($this->data($from, $last, array_map(static fn (int $i): string => "L{$i}", range(1, $lineCount))));
            $weeks = array_sum(array_map(static fn (array $month): int => \count($month['weeks']), $view['months']));
            self::assertSame($weeks * ($lineCount + 1), PlanningExportPdfRenderer::rowCount(new \DateTimeImmutable($from), new \DateTimeImmutable($last), $lineCount), "{$from} → {$last}");
        }
        self::assertLessThanOrEqual(PlanningExportPdfRenderer::MAX_ROWS, PlanningExportPdfRenderer::rowCount(new \DateTimeImmutable('2027-01-01'), new \DateTimeImmutable('2027-12-31'), 10), 'Ten lines over a whole year fit.');
    }
    // --- XLSX --------------------------------------------------------------------------

    public function testTheWorkbookHasAPlanningSheetWithRealDatesAndOneColumnPerLineInOrder(): void
    {
        $bytes = static::getContainer()->get(PlanningExportXlsxRenderer::class)->render($this->data('2026-10-01', '2026-10-31', ['Orthopédie', 'Membres supérieurs']));
        self::assertStringStartsWith("PK\x03\x04", $bytes, 'A real .xlsx (zip), never a renamed CSV.');

        $sheets = $this->readXlsx($bytes);
        self::assertSame(['Planning', 'Par personne', 'Informations'], array_keys($sheets), 'docs/decisions.md D200: what the document is, last.');

        $planning = $sheets['Planning'];
        self::assertSame(['Date', 'Jour', 'Orthopédie', 'Membres supérieurs'], $planning[0], 'A single period: no "Période" column, as before.');
        self::assertCount(1 + 31, $planning, 'One row per date, days without duty included.');
        self::assertInstanceOf(\DateTimeImmutable::class, $planning[1][0], 'A real Excel date.');
        self::assertSame('2026-10-01', $planning[1][0]->format('Y-m-d'));
        self::assertSame(['Jeudi', 'Alice Martin', self::LONG_NAME], \array_slice($planning[1], 1));
        self::assertSame('Vendredi', $planning[2][1]);
        self::assertSame('', $planning[2][3] ?? '', 'No duty: an empty cell.');
        self::assertSame('Non attribué (Jour) / Bob Durand (Nuit)', $planning[3][2]);
        self::assertSame('2026-10-31', $planning[31][0]->format('Y-m-d'));

        $sheetXml = $this->xlsxPart($bytes, 'xl/worksheets/sheet1.xml');
        self::assertStringContainsString('<autoFilter ref="A1:D32"', $sheetXml, 'Filters on the whole table.');
        self::assertStringContainsString('state="frozen"', $sheetXml, 'Header row frozen.');
        self::assertStringContainsString('<cols>', $sheetXml, 'Column widths set.');
    }

    public function testTheParPersonneSheetListsEveryCoveredDutySortedByPersonThenDate(): void
    {
        $sheets = $this->readXlsx(static::getContainer()->get(PlanningExportXlsxRenderer::class)->render($this->data('2026-10-01', '2026-10-03', ['Ortho', 'Membres sup.'])));
        $people = $sheets['Par personne'];

        self::assertSame(['Personne', 'Date', 'Jour', 'Ligne'], $people[0]);
        $rows = array_map(static fn (array $row): string => $row[0].'|'.$row[1]->format('Y-m-d').'|'.$row[2].'|'.$row[3], \array_slice($people, 1));
        self::assertSame([
            'Alice Martin|2026-10-01|Jeudi|Ortho',
            'Bob Durand|2026-10-03|Samedi|Ortho',
            self::LONG_NAME.'|2026-10-01|Jeudi|Membres sup.',
        ], $rows, 'Uncovered duties are not a person; sorted with French collation ("É" next to "E").');
    }

    public function testNoCellCanEverBecomeAFormula(): void
    {
        $data = new PlanningExportData(
            '=HYPERLINK("http://evil.example","x")',
            PlanningExportFormat::XLSX,
            new \DateTimeImmutable('2026-10-01'),
            new \DateTimeImmutable('2026-10-01'),
            new \DateTimeImmutable('2026-09-28 14:05'),
            [new PlanningExportLine('l1', '+SUM(1,2)'), new PlanningExportLine('l2', '@cmd')],
            [new PlanningExportDay(new \DateTimeImmutable('2026-10-01'), [
                [new PlanningExportItem('=1+1 Dupont', null)],
                [new PlanningExportItem('-2+3 Martin', null)],
            ])],
        );
        $bytes = static::getContainer()->get(PlanningExportXlsxRenderer::class)->render($data);

        foreach (['xl/worksheets/sheet1.xml', 'xl/worksheets/sheet2.xml'] as $part) {
            self::assertStringNotContainsString('<f>', $this->xlsxPart($bytes, $part), "{$part}: not a single formula.");
        }
        $sheets = $this->readXlsx($bytes);
        self::assertSame(["'+SUM(1,2)", "'@cmd"], \array_slice($sheets['Planning'][0], 2));
        self::assertSame(["'=1+1 Dupont", "'-2+3 Martin"], \array_slice($sheets['Planning'][1], 2));
        $people = array_map(static fn (array $row): string => $row[0].'|'.$row[3], \array_slice($sheets['Par personne'], 1));
        sort($people);
        self::assertSame(["'-2+3 Martin|'@cmd", "'=1+1 Dupont|'+SUM(1,2)"], $people);
    }

    public function testATitleWithXmlCharactersNeverBreaksTheWorkbook(): void
    {
        $data = new PlanningExportData(
            'Ortho & Trauma <b>"urgences"</b>',
            PlanningExportFormat::XLSX,
            new \DateTimeImmutable('2026-10-01'),
            new \DateTimeImmutable('2026-10-01'),
            new \DateTimeImmutable('2026-09-28 14:05'),
            [new PlanningExportLine('l1', 'A & B <C>')],
            [new PlanningExportDay(new \DateTimeImmutable('2026-10-01'), [[new PlanningExportItem('Zoé <O\'Brien> & co', null)]])],
        );
        $bytes = static::getContainer()->get(PlanningExportXlsxRenderer::class)->render($data);

        $sheets = $this->readXlsx($bytes);
        self::assertSame('A & B <C>', $sheets['Planning'][0][2]);
        self::assertSame('Zoé <O\'Brien> & co', $sheets['Planning'][1][2]);
        foreach (['xl/worksheets/sheet1.xml', 'docProps/core.xml'] as $part) {
            $document = new \DOMDocument();
            self::assertTrue(@$document->loadXML($this->xlsxPart($bytes, $part)), "{$part} is well-formed XML.");
        }
        self::assertStringContainsString('Ortho &amp;&amp; Trauma &lt;b&gt;', $this->xlsxPart($bytes, 'xl/worksheets/sheet1.xml'), 'Header text: "&" doubled for Excel, then XML-escaped.');
        self::assertStringContainsString('<dc:title>Ortho &amp; Trauma &lt;b&gt;', $this->xlsxPart($bytes, 'docProps/core.xml'));
    }

    public function testSafeTextOnlyTouchesFormulaTriggers(): void
    {
        foreach (['=A1', '+1', '-1', '@x', "\tx", "\rx"] as $dangerous) {
            self::assertSame("'".$dangerous, PlanningExportXlsxRenderer::safeText($dangerous));
        }
        foreach (['Dupont', 'Jean-Luc', 'Élodie', '', ' =x'] as $harmless) {
            self::assertSame($harmless, PlanningExportXlsxRenderer::safeText($harmless));
        }
    }

    // --- filename ----------------------------------------------------------------------

    public function testTheFilenameIsReadableAndFilesystemSafe(): void
    {
        $oct = new \DateTimeImmutable('2026-10-01');
        $dec = new \DateTimeImmutable('2026-12-31');

        self::assertSame('Gardes_Orthopedie_2026-10_2026-12.pdf', PlanningExportService::filename('Orthopédie', $oct, $dec, PlanningExportFormat::PDF));
        self::assertSame('Gardes_Orthopedie_2026-10_2026-12.xlsx', PlanningExportService::filename('Orthopédie', $oct, $dec, PlanningExportFormat::XLSX));
        self::assertSame('Gardes-Seniors_2026-10.pdf', PlanningExportService::filename('Gardes Seniors', $oct, $oct, PlanningExportFormat::PDF), '"Gardes" is not repeated; one month = one date.');
        self::assertSame('Gardes_etc-passwd-script-x-script_2026-10.pdf', PlanningExportService::filename('../../etc/passwd<script>x</script>', $oct, $oct, PlanningExportFormat::PDF));
        self::assertSame('Gardes_Planning_2026-10.pdf', PlanningExportService::filename('😀 / \\ :*?"<>|', $oct, $oct, PlanningExportFormat::PDF), 'Nothing usable left: a neutral name.');

        $long = PlanningExportService::filename(str_repeat('Très long titre ', 20), $oct, $dec, PlanningExportFormat::PDF);
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]+\.pdf$/', $long);
        self::assertLessThanOrEqual(90, \strlen($long));
    }

    /**
     * $from → $last, lines as given. Every 1st of the month: line 0 = Alice
     * Martin, line 1 = the long name; every 3rd: line 0 holds two duties
     * (Jour uncovered, Nuit Bob Durand). Nothing else.
     *
     * @param list<string> $labels
     */
    private function data(string $from, string $last, array $labels): PlanningExportData
    {
        $days = [];
        for ($date = new \DateTimeImmutable($from); $date <= new \DateTimeImmutable($last); $date = $date->modify('+1 day')) {
            $cells = array_fill(0, \count($labels), []);
            if ('01' === $date->format('d')) {
                $cells[0] = [new PlanningExportItem('Alice Martin', null)];
                if (isset($cells[1])) {
                    $cells[1] = [new PlanningExportItem(self::LONG_NAME, null)];
                }
            }
            if ('03' === $date->format('d')) {
                $cells[0] = [new PlanningExportItem(null, 'Jour'), new PlanningExportItem('Bob Durand', 'Nuit')];
            }
            $days[] = new PlanningExportDay($date, $cells);
        }

        return new PlanningExportData(
            'Gardes Orthopédie',
            PlanningExportFormat::PDF,
            new \DateTimeImmutable($from),
            new \DateTimeImmutable($last),
            new \DateTimeImmutable('2026-09-28 14:05', new \DateTimeZone('Europe/Brussels')),
            array_map(static fn (string $label, int $index): PlanningExportLine => new PlanningExportLine("line-{$index}", $label), $labels, array_keys($labels)),
            $days,
        );
    }
}
