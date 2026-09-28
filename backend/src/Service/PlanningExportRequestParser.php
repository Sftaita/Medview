<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Planning;
use App\Entity\PlanningLine;
use App\Exception\InvalidPlanningExportRequestException;
use App\Repository\PlanningLineRepository;

/**
 * Validates the body of POST /api/plannings/{id}/export
 * (docs/planning-export.md §API). Nothing the client sends is trusted:
 *
 *  - `lines` is the document order itself (no redundant `position`); each
 *    `stableId` must be an *active* line of *this* planning — looked up
 *    among the planning's own lines, so a line of another planning is
 *    simply unknown here;
 *  - `from`/`to` follow the project's half-open convention (`to`
 *    exclusive, like Planning::$endsAt and `/result`), must lie inside the
 *    planning, and default to its bounds;
 *  - `title` and each `label` are presentation text only: control
 *    characters are dropped, whitespace collapsed, length capped — they
 *    are never used as a path and never written to Planning/PlanningLine.
 */
final class PlanningExportRequestParser
{
    public const TITLE_MAX_LENGTH = 120;
    public const LABEL_MAX_LENGTH = 80;

    private const FIELDS = ['format', 'title', 'from', 'to', 'lines'];
    private const LINE_FIELDS = ['stableId', 'label'];

    public function __construct(private readonly PlanningLineRepository $lineRepository)
    {
    }

    /**
     * @param array<mixed> $raw the decoded JSON body
     *
     * @throws InvalidPlanningExportRequestException
     */
    public function parse(Planning $planning, array $raw): PlanningExportRequest
    {
        $errors = [];
        foreach (array_keys($raw) as $field) {
            if (!\in_array($field, self::FIELDS, true)) {
                $errors[(string) $field] = 'This field is not accepted.';
            }
        }

        $format = \is_string($raw['format'] ?? null) ? PlanningExportFormat::tryFrom($raw['format']) : null;
        if (null === $format) {
            $errors['format'] = 'Expected "pdf" or "xlsx".';
        }

        $title = $this->text($raw['title'] ?? null, 'title', self::TITLE_MAX_LENGTH, $errors);

        $planningFirst = $this->dateOnly($planning->getStartsAt());
        $planningEndExclusive = $this->dateOnly($planning->getEndsAt());
        $from = $this->date($raw['from'] ?? null, 'from', $errors) ?? $planningFirst;
        $to = $this->date($raw['to'] ?? null, 'to', $errors) ?? $planningEndExclusive;
        if (!isset($errors['from']) && $from < $planningFirst) {
            $errors['from'] = 'from must not be before the start of the planning.';
        }
        if (!isset($errors['to']) && $to > $planningEndExclusive) {
            $errors['to'] = 'to must not be after the end of the planning.';
        }
        if (!isset($errors['from']) && !isset($errors['to']) && $to <= $from) {
            $errors['to'] = 'to must be strictly after from.';
        }

        $lines = $this->lines($planning, $raw['lines'] ?? null, $errors);

        if ([] !== $errors) {
            throw new InvalidPlanningExportRequestException($errors);
        }

        \assert(null !== $format && null !== $title);

        // Too large a PDF is refused here, never half-way through dompdf (PlanningExportPdfRenderer::MAX_ROWS).
        $rows = PlanningExportPdfRenderer::rowCount($from, $to->modify('-1 day'), \count($lines));
        if (PlanningExportFormat::PDF === $format && $rows > PlanningExportPdfRenderer::MAX_ROWS) {
            throw new InvalidPlanningExportRequestException(['size' => 'This PDF would hold '.$rows.' table rows, more than the '.PlanningExportPdfRenderer::MAX_ROWS.' allowed: shorten the period, export fewer lines, or choose xlsx.']);
        }

        return new PlanningExportRequest($planning, $format, $title, $from, $to, $lines);
    }

    /**
     * @param array<string, string> $errors
     *
     * @return list<PlanningExportLineChoice>
     */
    private function lines(Planning $planning, mixed $raw, array &$errors): array
    {
        if (!\is_array($raw) || !array_is_list($raw)) {
            $errors['lines'] = 'Expected a list of lines.';

            return [];
        }
        if ([] === $raw) {
            $errors['lines'] = 'Select at least one line.';

            return [];
        }

        /** @var array<string, PlanningLine> $activeLines */
        $activeLines = [];
        foreach ($this->lineRepository->findByPlanning($planning) as $line) {
            if ($line->isActive()) {
                $activeLines[(string) $line->getStableId()] = $line;
            }
        }

        $choices = [];
        $seen = [];
        foreach ($raw as $index => $item) {
            $path = "lines[{$index}]";
            if (!\is_array($item)) {
                $errors[$path] = 'Expected an object with stableId and label.';
                continue;
            }
            foreach (array_keys($item) as $field) {
                if (!\in_array($field, self::LINE_FIELDS, true)) {
                    $errors["{$path}.{$field}"] = 'This field is not accepted.';
                }
            }

            $stableId = \is_string($item['stableId'] ?? null) ? $item['stableId'] : '';
            $line = $activeLines[$stableId] ?? null;
            if (null === $line) {
                $errors["{$path}.stableId"] = 'Not an active line of this planning.';
            } elseif (isset($seen[$stableId])) {
                $errors["{$path}.stableId"] = 'This line is already in the list.';
                $line = null;
            }
            $seen[$stableId] = true;

            $label = $this->text($item['label'] ?? null, "{$path}.label", self::LABEL_MAX_LENGTH, $errors);
            if (null !== $line && null !== $label) {
                $choices[] = new PlanningExportLineChoice($line, $label);
            }
        }

        return $choices;
    }

    /**
     * @param array<string, string> $errors
     */
    private function text(mixed $raw, string $field, int $maxLength, array &$errors): ?string
    {
        if (!\is_string($raw)) {
            $errors[$field] = 'This value is required.';

            return null;
        }

        $text = trim((string) preg_replace('/[\p{Cc}\p{Z}]+/u', ' ', $raw));
        if (!mb_check_encoding($raw, 'UTF-8') || '' === $text) {
            $errors[$field] = 'This value is required.';

            return null;
        }
        if (mb_strlen($text) > $maxLength) {
            $errors[$field] = "This value must not exceed {$maxLength} characters.";

            return null;
        }

        return $text;
    }

    /**
     * @param array<string, string> $errors
     */
    private function date(mixed $raw, string $field, array &$errors): ?\DateTimeImmutable
    {
        if (null === $raw || '' === $raw) {
            return null;
        }

        $date = \is_string($raw) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $raw) : false;
        if (false === $date || $date->format('Y-m-d') !== $raw) {
            $errors[$field] = 'This value is not a valid date (expected YYYY-MM-DD).';

            return null;
        }

        return $date;
    }

    private function dateOnly(\DateTimeImmutable $date): \DateTimeImmutable
    {
        return new \DateTimeImmutable($date->format('Y-m-d'));
    }
}
