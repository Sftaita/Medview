<?php

declare(strict_types=1);

namespace App\Service;

/**
 * The two export formats of the current calendar (docs/planning-export.md).
 */
enum PlanningExportFormat: string
{
    case PDF = 'pdf';
    case XLSX = 'xlsx';

    public function contentType(): string
    {
        return match ($this) {
            self::PDF => 'application/pdf',
            self::XLSX => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        };
    }
}
