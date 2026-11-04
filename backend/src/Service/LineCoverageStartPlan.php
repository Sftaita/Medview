<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Duty;
use App\Entity\PlanningLine;

/**
 * What a coverage start would do to a line (docs/decisions.md D233), read
 * without writing anything. Only duties nobody holds are ever retired; a duty
 * someone holds is left as it is and listed, never removed or reassigned.
 */
final readonly class LineCoverageStartPlan
{
    /**
     * @param list<Duty>                $retired the duties before the date nobody holds: retired from the active calendar
     * @param list<array{duty: Duty, holderFirstName: string, holderLastName: string}> $kept the duties before the date someone holds: untouched
     */
    public function __construct(
        public PlanningLine $line,
        public ?\DateTimeImmutable $previousStartsOn,
        public ?\DateTimeImmutable $newStartsOn,
        public array $retired,
        public array $kept,
    ) {
    }

    public function changesAnything(): bool
    {
        return $this->previousStartsOn?->format('Y-m-d') !== $this->newStartsOn?->format('Y-m-d');
    }

    /** A date that touches no duty needs no confirmation. */
    public function confirmationRequired(): bool
    {
        return $this->changesAnything() && ([] !== $this->retired || [] !== $this->kept);
    }

    /** What is confirmed is what is done: a calendar that changed since the preview gives another hash. */
    public function hash(): string
    {
        $ids = static fn (Duty $duty): string => (string) $duty->getStableId();

        return hash('sha256', json_encode([
            'line' => (string) $this->line->getStableId(),
            'from' => $this->previousStartsOn?->format('Y-m-d'),
            'to' => $this->newStartsOn?->format('Y-m-d'),
            'retired' => array_map($ids, $this->retired),
            'kept' => array_map(static fn (array $row): string => $ids($row['duty']), $this->kept),
        ], \JSON_THROW_ON_ERROR));
    }
}
