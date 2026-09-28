<?php

declare(strict_types=1);

namespace App\Service;

/**
 * The one intermediate model both export formats render
 * (docs/planning-export.md): the current calendar restricted to the chosen
 * lines and dates, already in document order. Neither renderer ever reads
 * the database — two formats, one interpretation of the planning.
 */
final readonly class PlanningExportData
{
    /**
     * @param list<PlanningExportLine> $lines document order
     * @param list<PlanningExportDay>  $days  every date from $first to $last, chronological
     */
    public function __construct(
        public string $title,
        public PlanningExportFormat $format,
        public \DateTimeImmutable $first,
        public \DateTimeImmutable $last,
        public \DateTimeImmutable $generatedAt,
        public array $lines,
        public array $days,
    ) {
    }
}
