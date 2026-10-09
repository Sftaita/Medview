<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Planning;

/**
 * "Exporter les absences (PDF)" (docs/availability.md §11, docs/decisions.md
 * D181): the planning's participants and their declared unavailabilities
 * over the planning period, as of now. Read only — nothing is written, not
 * even an audit row. Unlike the calendar export (D150), it does not need a
 * publication: absences are collected before anything is generated.
 */
final class PlanningAbsenceExportService
{
    private const FILENAME_SLUG_MAX_LENGTH = 60;

    public function __construct(
        private readonly PlanningAbsenceExportDataBuilder $dataBuilder,
        private readonly PlanningAbsenceExportPdfRenderer $pdfRenderer,
    ) {
    }

    public function export(Planning $planning): PlanningExportFile
    {
        $data = $this->dataBuilder->build($planning);

        return new PlanningExportFile(
            $this->pdfRenderer->render($data),
            self::filename($data->planningName, $data->first, $data->last),
            'application/pdf',
        );
    }

    /**
     * "MedVue_Absences_Gardes-Orthopedie_2027-01-15_2027-03-14.pdf": ASCII
     * letters, digits and "-" only (accents transliterated), then the first
     * and last day of the planning (both inclusive). The name only feeds
     * this slug, never a path.
     */
    public static function filename(string $planningName, string $first, string $last): string
    {
        $ascii = (string) transliterator_transliterate('Any-Latin; Latin-ASCII', $planningName);
        $slug = trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', $ascii), '-');
        $slug = rtrim(substr($slug, 0, self::FILENAME_SLUG_MAX_LENGTH), '-');

        return 'MedVue_Absences_'.('' === $slug ? 'Planning' : $slug).'_'.$first.'_'.$last.'.pdf';
    }
}
