<?php

declare(strict_types=1);

namespace App\Service;

use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\DateTimeCell;
use OpenSpout\Common\Entity\Cell\EmptyCell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\CellVerticalAlignment;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\AutoFilter;
use OpenSpout\Writer\Common\Entity\Sheet;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Options\HeaderFooter;
use OpenSpout\Writer\XLSX\Options\PageOrientation;
use OpenSpout\Writer\XLSX\Options\PageSetup;
use OpenSpout\Writer\XLSX\Options\PaperSize;
use OpenSpout\Writer\XLSX\Properties;
use OpenSpout\Writer\XLSX\Writer;

/**
 * The Excel export (docs/planning-export.md §Excel), written with
 * openspout/openspout — a real .xlsx, two sheets:
 *
 *  - "Planning": Date | Jour | one column per exported line (its export
 *    name, in the chosen order), one row per date of the range, days
 *    without any duty included;
 *  - "Par personne": Personne | Date | Jour | Ligne, one row per covered
 *    exported duty, sorted by person then date then line order.
 *
 * Dates are real Excel dates. Every text cell is built as an explicit
 * StringCell (OpenSpout's Cell::fromValue() would turn a string starting
 * with "=" into a formula) and a value starting with a formula trigger is
 * additionally prefixed with an apostrophe (safeText()), so neither a
 * user-typed alias nor a person's name can ever become a formula, even once
 * a cell is edited and re-entered in a spreadsheet application.
 *
 * Renders PlanningExportData only — it never reads the database.
 */
final class PlanningExportXlsxRenderer
{
    public const PLANNING_SHEET = 'Planning';
    public const PEOPLE_SHEET = 'Par personne';
    public const INFO_SHEET = 'Informations';
    public const PERIOD_COLUMN = 'Période';

    private const WEEKDAYS = ['Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi', 'Dimanche'];
    private const DATE_FORMAT = 'dd/mm/yyyy';

    public function render(PlanningExportData $data): string
    {
        $path = tempnam(sys_get_temp_dir(), 'medvue-export-');
        if (false === $path) {
            throw new \RuntimeException('Cannot create a temporary file for the export.');
        }

        try {
            $writer = new Writer($this->options($data));
            $writer->openToFile($path);

            $planningSheet = $writer->getCurrentSheet();
            $planningSheet->setName(self::PLANNING_SHEET);
            $this->writePlanningSheet($writer, $planningSheet, $data);

            $peopleSheet = $writer->addNewSheetAndMakeItCurrent();
            $peopleSheet->setName(self::PEOPLE_SHEET);
            $this->writePeopleSheet($writer, $peopleSheet, $data);
            $infoSheet = $writer->addNewSheetAndMakeItCurrent();
            $infoSheet->setName(self::INFO_SHEET);
            $this->writeInfoSheet($writer, $infoSheet, $data);

            $writer->setCurrentSheet($planningSheet);
            $writer->close();

            return (string) file_get_contents($path);
        } finally {
            @unlink($path);
        }
    }

    /**
     * Text that can never be read as a formula (OWASP "CSV injection"):
     * a leading =, +, -, @, tab or line break gets an apostrophe in front.
     */
    public static function safeText(string $text): string
    {
        return 1 === preg_match('/^[=+\-@\t\r\n]/', $text) ? "'".$text : $text;
    }

    /**
     * The text of one "Planning" cell: each duty's holder (or "Non
     * attribué"), with the duty type only when the cell holds several.
     *
     * @param list<PlanningExportItem> $items
     */
    public static function cellText(array $items): string
    {
        return implode(' / ', array_map(
            static fn (PlanningExportItem $item): string => ($item->personName ?? $item->gapLabel()).(null !== $item->dutyTypeName ? ' ('.$item->dutyTypeName.')' : ''),
            $items,
        ));
    }

    private function options(PlanningExportData $data): Options
    {
        $generated = 'Généré le '.FrenchDate::long($data->generatedAt).' à '.$data->generatedAt->format('H:i');
        $period = 'Du '.FrenchDate::long($data->first).' au '.FrenchDate::long($data->last);

        return new Options(
            pageSetup: new PageSetup(PageOrientation::LANDSCAPE, PaperSize::A4, fitToHeight: 0, fitToWidth: 1),
            headerFooter: new HeaderFooter(
                oddHeader: self::xml('&L'.$this->headerText($data->title).(null !== $data->headingLabel() ? '&C'.$this->headerText($data->headingLabel()) : '').'&R'.$this->headerText($period)),
                oddFooter: self::xml('&L'.$this->headerText($generated).'&RPage &P / &N'),
            ),
            properties: new Properties(
                title: self::xml($data->title),
                subject: self::xml($period),
                application: 'MedVue',
                creator: 'MedVue',
                lastModifiedBy: 'MedVue',
            ),
        );
    }

    private function writePlanningSheet(Writer $writer, Sheet $sheet, PlanningExportData $data): void
    {
        $header = $this->headerStyle();
        // docs/decisions.md D200: a "Période" column on a planning with several periods, after the weekday.
        $lead = $data->multiPeriod ? 3 : 2;
        $headers = ['Date', 'Jour', ...($data->multiPeriod ? [self::PERIOD_COLUMN] : []), ...array_map(static fn (PlanningExportLine $line): string => $line->label, $data->lines)];
        $writer->addRow(new Row(array_map(fn (string $text): Cell => $this->text($text, $header), $headers)));

        $widths = array_map(static fn (string $text): int => mb_strlen($text), $headers);
        foreach ($data->days as $day) {
            $weekend = (int) $day->date->format('N') >= 6;
            $style = $this->rowStyle($weekend);
            $cells = [
                new DateTimeCell($day->date, $style->withFormat(self::DATE_FORMAT)),
                $this->text(self::WEEKDAYS[(int) $day->date->format('N') - 1], $style),
            ];
            if ($data->multiPeriod) {
                $cells[] = null === $day->periodNumber ? new EmptyCell(null, $style) : $this->text('Période '.$day->periodNumber, $style);
            }
            foreach ($day->cells as $column => $items) {
                $text = $day->isUnpublished($column) ? PlanningExportDay::UNPUBLISHED : self::cellText($items);
                $cells[] = '' === $text ? new EmptyCell(null, $style) : $this->text($text, $style);
                $widths[$column + $lead] = max($widths[$column + $lead], mb_strlen($text));
            }
            $writer->addRow(new Row($cells));
        }

        $sheet->setColumnWidth(12, 1);
        $sheet->setColumnWidth(11, 2);
        if ($data->multiPeriod) {
            $sheet->setColumnWidth(11, 3);
        }
        foreach ($data->lines as $column => $line) {
            $sheet->setColumnWidth((float) min(45, max(16, $widths[$column + $lead] + 3)), $column + $lead + 1);
        }
        $this->finishTable($sheet, \count($headers), 1 + \count($data->days));
        $sheet->setPrintTitleRows('$1:$1');
    }

    private function writePeopleSheet(Writer $writer, Sheet $sheet, PlanningExportData $data): void
    {
        $rows = [];
        foreach ($data->days as $day) {
            foreach ($day->cells as $column => $items) {
                foreach ($items as $item) {
                    if (null !== $item->personName) {
                        $rows[] = ['person' => $item->personName, 'date' => $day->date, 'column' => $column, 'period' => $day->periodNumber];
                    }
                }
            }
        }

        $collator = new \Collator('fr_FR');
        usort($rows, static fn (array $a, array $b): int => [(int) $collator->compare($a['person'], $b['person']), $a['date'], $a['column']] <=> [0, $b['date'], $b['column']]);

        $header = $this->headerStyle();
        // docs/decisions.md D200: the period last, so the first four columns never move.
        $headers = ['Personne', 'Date', 'Jour', 'Ligne', ...($data->multiPeriod ? [self::PERIOD_COLUMN] : [])];
        $writer->addRow(new Row(array_map(fn (string $text): Cell => $this->text($text, $header), $headers)));

        $personWidth = mb_strlen('Personne');
        $lineWidth = mb_strlen('Ligne');
        $style = $this->rowStyle(false);
        foreach ($rows as $row) {
            $label = $data->lines[$row['column']]->label;
            $writer->addRow(new Row([
                $this->text($row['person'], $style),
                new DateTimeCell($row['date'], $style->withFormat(self::DATE_FORMAT)),
                $this->text(self::WEEKDAYS[(int) $row['date']->format('N') - 1], $style),
                $this->text($label, $style),
                ...($data->multiPeriod ? [null === $row['period'] ? new EmptyCell(null, $style) : $this->text('Période '.$row['period'], $style)] : []),
            ]));
            $personWidth = max($personWidth, mb_strlen($row['person']));
            $lineWidth = max($lineWidth, mb_strlen($label));
        }

        $sheet->setColumnWidth((float) min(40, $personWidth + 3), 1);
        $sheet->setColumnWidth(12, 2);
        $sheet->setColumnWidth(11, 3);
        $sheet->setColumnWidth((float) min(40, $lineWidth + 3), 4);
        if ($data->multiPeriod) {
            $sheet->setColumnWidth(11, 5);
        }
        $this->finishTable($sheet, \count($headers), 1 + \count($rows));
    }

    /**
     * "Informations" (docs/decisions.md D200): what the document is — its
     * nature (current calendar, labelled when it differs from the last
     * diffusion, or the diffused state), the dates, the periods covered and
     * the line periods left out because they are not published. Never a
     * publication: an export replaces no stored publication PDF.
     */
    private function writeInfoSheet(Writer $writer, Sheet $sheet, PlanningExportData $data): void
    {
        $rows = [
            ['Document', $data->title],
            ['Dates', 'Du '.FrenchDate::long($data->first).' au '.FrenchDate::long($data->last)],
            ['Nature', $data->natureLabel],
        ];
        if ($data->multiPeriod) {
            $rows[] = ['Périodes', PlanningExportPdfRenderer::periodsLabel($data->periods)];
        }
        if ([] !== $data->excluded) {
            $rows[] = ['Non inclus car non publiés', implode(', ', $data->excluded)];
        }
        $rows[] = ['Généré le', FrenchDate::long($data->generatedAt).' à '.$data->generatedAt->format('H:i')];

        $label = new Style(fontBold: true, cellVerticalAlignment: CellVerticalAlignment::TOP);
        $value = $this->rowStyle(false)->withShouldWrapText(true);
        foreach ($rows as [$name, $text]) {
            $writer->addRow(new Row([$this->text($name, $label), $this->text($text, $value)]));
        }
        $sheet->setColumnWidth(28, 1);
        $sheet->setColumnWidth(90, 2);
    }

    /** Header row frozen, filters on every column of the table. */
    private function finishTable(Sheet $sheet, int $columnCount, int $rowCount): void
    {
        $sheet->setSheetView((new SheetView())->withFreezeRow(2)->withTabSelected(self::PLANNING_SHEET === $sheet->getName()));
        $sheet->setAutoFilter(new AutoFilter(0, 1, $columnCount - 1, max(1, $rowCount)));
    }

    private function text(string $text, Style $style): StringCell
    {
        return new StringCell(self::safeText($text), $style);
    }

    private function headerStyle(): Style
    {
        return new Style(fontBold: true, fontColor: 'FFFFFF', backgroundColor: '2C7D5F', cellVerticalAlignment: CellVerticalAlignment::CENTER);
    }

    private function rowStyle(bool $weekend): Style
    {
        $style = new Style(cellVerticalAlignment: CellVerticalAlignment::TOP);

        return $weekend ? $style->withBackgroundColor('EEF3F6') : $style;
    }

    /**
     * OpenSpout writes header/footer and document properties into the XML
     * as given, unescaped (unlike cell values): a title holding "&" or "<"
     * would corrupt the file — or inject markup — without this.
     */
    private static function xml(string $text): string
    {
        return htmlspecialchars($text, \ENT_XML1 | \ENT_QUOTES, 'UTF-8');
    }

    /**
     * Excel header/footer codes start with "&": a literal one is doubled.
     * Capped at 80 characters *before* doubling so a header section can
     * never push the whole header past Excel's 255-character limit.
     */
    private function headerText(string $text): string
    {
        return str_replace('&', '&&', mb_substr($text, 0, 80));
    }
}
