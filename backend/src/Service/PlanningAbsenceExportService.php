<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Planning;
use App\Exception\InvalidPlanningExportRequestException;
use App\Repository\PlanningCycleRepository;

/**
 * "Exporter les absences (PDF)" (docs/availability.md §11, docs/decisions.md
 * D181): the planning's participants and their declared unavailabilities
 * over the planning period, as of now. Read only — nothing is written, not
 * even an audit row. Unlike the calendar export (D150), it does not need a
 * publication: absences are collected before anything is generated.
 *
 * Range (docs/decisions.md D200): the whole planning by default, one
 * planning period (`period`, its stableId) or explicit dates (`from`, `to`
 * exclusive) — never both, always inside the planning. On a planning with
 * several periods, the document names the periods it covers.
 */
final class PlanningAbsenceExportService
{
    private const FILENAME_SLUG_MAX_LENGTH = 60;

    public function __construct(
        private readonly PlanningAbsenceExportDataBuilder $dataBuilder,
        private readonly PlanningAbsenceExportPdfRenderer $pdfRenderer,
        private readonly PlanningCycleRepository $cycleRepository,
    ) {
    }

    /**
     * @throws InvalidPlanningExportRequestException 422, with one violation per field
     */
    public function export(Planning $planning, ?string $period = null, ?string $from = null, ?string $to = null): PlanningExportFile
    {
        $planningFirst = $planning->getStartsAt()->format('Y-m-d');
        $planningEnd = $planning->getEndsAt()->format('Y-m-d');
        $first = $planningFirst;
        $end = $planningEnd;
        $errors = [];
        if (null !== $period) {
            $cycle = $this->cycleRepository->findOneForPlanning($planning, $period);
            if (null === $cycle) {
                $errors['period'] = 'Not a period of this planning.';
            } elseif (null !== $from || null !== $to) {
                $errors['period'] = 'Give either period or from/to, not both.';
            } else {
                $first = $cycle->getStartsAt()->format('Y-m-d');
                $end = $cycle->getEndsAt()->format('Y-m-d');
            }
        }
        foreach (['from' => $from, 'to' => $to] as $field => $raw) {
            if (null === $raw) {
                continue;
            }
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $raw);
            if (false === $date || $date->format('Y-m-d') !== $raw) {
                $errors[$field] = 'This value is not a valid date (expected YYYY-MM-DD).';
            } elseif ('from' === $field) {
                $first = $raw;
            } else {
                $end = $raw;
            }
        }
        if (!isset($errors['from']) && $first < $planningFirst) {
            $errors['from'] = 'from must not be before the start of the planning.';
        }
        if (!isset($errors['to']) && $end > $planningEnd) {
            $errors['to'] = 'to must not be after the end of the planning.';
        }
        if ([] === $errors && $end <= $first) {
            $errors['to'] = 'to must be strictly after from.';
        }
        if ([] !== $errors) {
            throw new InvalidPlanningExportRequestException($errors);
        }

        $data = $this->dataBuilder->build($planning, $first, $end, self::scopeLabel($planning, $first, $end));

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

    /**
     * "Période 2", "Périodes 1 à 3": the planning periods [$first, $end[
     * touches — null on a planning with a single period, as before.
     */
    public static function scopeLabel(Planning $planning, string $first, string $end): ?string
    {
        $cycles = $planning->getCycles();
        if (\count($cycles) < 2) {
            return null;
        }
        $numbers = [];
        foreach ($cycles as $cycle) {
            if ($cycle->getStartsAt()->format('Y-m-d') < $end && $cycle->getEndsAt()->format('Y-m-d') > $first) {
                $numbers[] = $cycle->getNumber();
            }
        }

        return match (\count($numbers)) {
            0 => null,
            1 => 'Période '.$numbers[0],
            default => 'Périodes '.min($numbers).' à '.max($numbers),
        };
    }
}
