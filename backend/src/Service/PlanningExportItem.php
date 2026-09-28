<?php

declare(strict_types=1);

namespace App\Service;

/**
 * One duty of an exported cell: who holds it right now (null = uncovered)
 * and, only when the cell holds several duties, which duty it is.
 *
 * `$undetermined` (docs/decisions.md D166): an uncovered reinforcement
 * whose demand cannot be evaluated — its own explicit label, never "Non
 * attribué" nor absent. (A reinforcement nobody needs and nobody holds is
 * never an item at all: PlanningExportDataBuilder leaves it out.) Both
 * renderers read the label from gapLabel(), never deciding it themselves.
 */
final readonly class PlanningExportItem
{
    public const UNCOVERED = 'Non attribué';
    public const UNDETERMINED = 'Renfort non évalué';

    public function __construct(
        public ?string $personName,
        public ?string $dutyTypeName,
        public bool $undetermined = false,
    ) {
    }

    /** What an uncovered item reads as; null when someone holds it. */
    public function gapLabel(): ?string
    {
        if (null !== $this->personName) {
            return null;
        }

        return $this->undetermined ? self::UNDETERMINED : self::UNCOVERED;
    }
}
