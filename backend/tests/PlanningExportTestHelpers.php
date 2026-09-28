<?php

declare(strict_types=1);

namespace App\Tests;

use OpenSpout\Reader\XLSX\Reader;

/**
 * Reads an exported .xlsx back (docs/planning-export.md) so tests check
 * what the file really contains, not only that some bytes came out.
 */
trait PlanningExportTestHelpers
{
    /**
     * @return array<string, list<list<mixed>>> sheet name → rows → cell values (dates as DateTimeImmutable)
     */
    private function readXlsx(string $bytes): array
    {
        $path = $this->xlsxOnDisk($bytes);
        try {
            $reader = new Reader();
            $reader->open($path);
            $sheets = [];
            foreach ($reader->getSheetIterator() as $sheet) {
                $rows = [];
                foreach ($sheet->getRowIterator() as $row) {
                    $rows[] = $row->toArray();
                }
                $sheets[$sheet->getName()] = $rows;
            }
            $reader->close();

            return $sheets;
        } finally {
            @unlink($path);
        }
    }

    /**
     * One raw part of the .xlsx archive (e.g. "xl/worksheets/sheet1.xml").
     */
    private function xlsxPart(string $bytes, string $name): string
    {
        $path = $this->xlsxOnDisk($bytes);
        try {
            $zip = new \ZipArchive();
            self::assertTrue($zip->open($path), 'A real .xlsx is a zip archive.');
            $content = $zip->getFromName($name);
            $zip->close();
            self::assertIsString($content, "{$name} is missing from the archive.");

            return $content;
        } finally {
            @unlink($path);
        }
    }

    private function xlsxOnDisk(string $bytes): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'medvue-test-xlsx-');
        file_put_contents($path, $bytes);

        return $path;
    }

    /** Pages of a dompdf document (page objects are never inside compressed streams). */
    private function pdfPageCount(string $bytes): int
    {
        return (int) preg_match_all('#/Type\s*/Page\b(?!s)#', $bytes);
    }
}
