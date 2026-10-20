<?php

declare(strict_types=1);

namespace App\Service;

/**
 * The one intermediate model both export formats render
 * (docs/planning-export.md): the calendar restricted to the chosen lines
 * and dates, already in document order. Neither renderer ever reads the
 * database — two formats, one interpretation of the planning.
 *
 * What the document is (docs/decisions.md D200), decided by
 * PlanningExportDataBuilder and only printed by the renderers:
 *  - OPERATIONAL_CHANGED: a manager's export of the current calendar that
 *    differs from the last diffusion — labelled OPERATIONAL_CHANGED_LABEL;
 *  - OPERATIONAL: a manager's export of the current calendar identical to
 *    the last diffusion over the exported scope;
 *  - PUBLISHED: a member's export — exactly what was last diffused, never
 *    a change made since.
 * Only PUBLISHED line periods are ever exported; the others are listed in
 * $excluded and their days read "Non publiée".
 */
final readonly class PlanningExportData
{
    public const OPERATIONAL_CHANGED = 'OPERATIONAL_CHANGED';
    public const OPERATIONAL = 'OPERATIONAL';
    public const PUBLISHED = 'PUBLISHED';

    public const OPERATIONAL_CHANGED_LABEL = 'Calendrier opérationnel — modifications non publiées possibles';
    public const OPERATIONAL_LABEL = 'Calendrier courant — identique à la dernière diffusion';

    /**
     * @param list<PlanningExportLine>                                                      $lines    document order
     * @param list<PlanningExportDay>                                                       $days     every date from $first to $last, chronological
     * @param list<array{number: int, first: \DateTimeImmutable, last: \DateTimeImmutable}> $periods  the planning's periods the range touches, clipped to it
     * @param list<string>                                                                  $excluded "Période 2 — Seniors": chosen line periods left out, not published
     */
    public function __construct(
        public string $title,
        public PlanningExportFormat $format,
        public \DateTimeImmutable $first,
        public \DateTimeImmutable $last,
        public \DateTimeImmutable $generatedAt,
        public array $lines,
        public array $days,
        public string $nature = self::OPERATIONAL,
        public string $natureLabel = self::OPERATIONAL_LABEL,
        public bool $multiPeriod = false,
        public array $periods = [],
        public array $excluded = [],
    ) {
    }

    /** The label printed in the document's heading — never for a current calendar identical to the diffusion. */
    public function headingLabel(): ?string
    {
        return self::OPERATIONAL === $this->nature ? null : $this->natureLabel;
    }
}
