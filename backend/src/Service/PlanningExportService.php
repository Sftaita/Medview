<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Planning;
use App\Exception\InvalidPlanningExportRequestException;
use App\Exception\PlanningNotYetPublishedException;
use App\Repository\PlanningPublicationRepository;

/**
 * "Exporter le planning" (docs/planning-export.md): request → one
 * intermediate model → PDF or XLSX. Exportable once the planning has been
 * published at least once (a PlanningPublication exists, D143); what is
 * exported is then the *current* calendar, unpublished edits included —
 * exactly what the calendar screen shows. Exporting never writes anything:
 * not a publication, not a generation, not a name.
 */
final class PlanningExportService
{
    private const FILENAME_SLUG_MAX_LENGTH = 60;

    public function __construct(
        private readonly PlanningPublicationRepository $publicationRepository,
        private readonly PlanningExportRequestParser $requestParser,
        private readonly PlanningExportDataBuilder $dataBuilder,
        private readonly PlanningExportPdfRenderer $pdfRenderer,
        private readonly PlanningExportXlsxRenderer $xlsxRenderer,
    ) {
    }

    /**
     * @param array<mixed> $raw the decoded JSON body
     *
     * @throws PlanningNotYetPublishedException
     * @throws InvalidPlanningExportRequestException
     */
    public function export(Planning $planning, array $raw): PlanningExportFile
    {
        if (null === $this->publicationRepository->findLatestForPlanning($planning)) {
            throw new PlanningNotYetPublishedException();
        }

        $data = $this->dataBuilder->build($this->requestParser->parse($planning, $raw));

        return new PlanningExportFile(
            match ($data->format) {
                PlanningExportFormat::PDF => $this->pdfRenderer->render($data),
                PlanningExportFormat::XLSX => $this->xlsxRenderer->render($data),
            },
            self::filename($data->title, $data->first, $data->last, $data->format),
            $data->format->contentType(),
        );
    }

    /**
     * "Gardes_Orthopedie_2026-10_2026-12.pdf": ASCII letters, digits and
     * "-" only (accents transliterated), the months covered, the format's
     * extension. The title is never used as a path — it only feeds this
     * slug. "Gardes_" is not repeated when the title already starts with it.
     */
    public static function filename(string $title, \DateTimeImmutable $first, \DateTimeImmutable $last, PlanningExportFormat $format): string
    {
        $ascii = (string) transliterator_transliterate('Any-Latin; Latin-ASCII', $title);
        $slug = trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', $ascii), '-');
        $slug = rtrim(substr($slug, 0, self::FILENAME_SLUG_MAX_LENGTH), '-');
        if ('' === $slug) {
            $slug = 'Planning';
        }
        if (1 !== preg_match('/^gardes(-|$)/i', $slug)) {
            $slug = 'Gardes_'.$slug;
        }

        $months = $first->format('Y-m') === $last->format('Y-m') ? $first->format('Y-m') : $first->format('Y-m').'_'.$last->format('Y-m');

        return $slug.'_'.$months.'.'.$format->value;
    }
}
