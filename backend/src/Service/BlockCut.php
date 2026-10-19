<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\PlanningLine;

/**
 * One occurrence of an atomic block of duties (a recurring DutyPattern of
 * two days or more, docs/allocation-algorithm.md §9) that a boundary between
 * two periods would cut in two (docs/planning-cycles.md §5.1, lot L3): its
 * first day is before the boundary, its last day on or after it. Such an
 * occurrence is materialized in neither period — a block is never split —
 * so its days would silently stay without any duty; the one exception is a
 * historical boundary (docs/decisions.md D192), where its days after the
 * boundary become a remainder block.
 */
final readonly class BlockCut
{
    /**
     * @param list<\DateTimeImmutable> $days every day of the occurrence, in order
     */
    public function __construct(
        public ?PlanningLine $line,
        public string $blockName,
        public \DateTimeImmutable $boundary,
        public \DateTimeImmutable $firstDay,
        public \DateTimeImmutable $lastDay,
        public array $days = [],
    ) {
    }

    /**
     * @return list<\DateTimeImmutable> the days before the boundary
     */
    public function earlierDays(): array
    {
        return array_values(array_filter($this->days, fn (\DateTimeImmutable $day): bool => $day->format('Y-m-d') < $this->boundary->format('Y-m-d')));
    }

    /**
     * @return list<\DateTimeImmutable> the days on or after the boundary
     */
    public function remainderDays(): array
    {
        return array_values(array_filter($this->days, fn (\DateTimeImmutable $day): bool => $day->format('Y-m-d') >= $this->boundary->format('Y-m-d')));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'lineStableId' => null !== $this->line ? (string) $this->line->getStableId() : null,
            'lineName' => $this->line?->getName(),
            'blockName' => $this->blockName,
            'boundary' => $this->boundary->format('Y-m-d'),
            'firstDay' => $this->firstDay->format('Y-m-d'),
            'lastDay' => $this->lastDay->format('Y-m-d'),
            'earlierDays' => array_map(static fn (\DateTimeImmutable $d): string => $d->format('Y-m-d'), $this->earlierDays()),
            'remainderDays' => array_map(static fn (\DateTimeImmutable $d): string => $d->format('Y-m-d'), $this->remainderDays()),
        ];
    }
}
