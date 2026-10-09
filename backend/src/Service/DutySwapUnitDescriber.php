<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Duty;
use App\Entity\PlanningLine;

/**
 * One duty unit as the swap workflow shows it (docs/duty-swaps.md §9): a
 * block once, with all its days, like "Mes gardes" (D168). The same array
 * feeds the API and is frozen into the email payloads, so an email never
 * describes a duty differently from the screen.
 */
final class DutySwapUnitDescriber
{
    public function __construct(
        private readonly ReassignmentCandidateService $candidateService,
    ) {
    }

    /**
     * @return array{dutyStableId: string, planningStableId: string, planningName: string, lineStableId: string, lineName: string, dutyTypeName: string, blockName: string|null, dates: list<string>, startsAt: string, endsAt: string}
     */
    public function describe(Duty $duty, PlanningLine $line): array
    {
        $block = $this->candidateService->blockDuties($duty);
        $startsAt = min(array_map(static fn (Duty $d): \DateTimeImmutable => $d->getStartsAt(), $block));
        $endsAt = max(array_map(static fn (Duty $d): \DateTimeImmutable => $d->getEndsAt(), $block));
        $dates = array_values(array_unique(array_map(static fn (Duty $d): string => $d->getLocalDate()->format('Y-m-d'), $block)));
        sort($dates);

        return [
            'dutyStableId' => (string) $block[0]->getStableId(),
            'planningStableId' => (string) $line->getPlanning()->getStableId(),
            'planningName' => $line->getPlanning()->getName(),
            'lineStableId' => (string) $line->getStableId(),
            'lineName' => $line->getName(),
            'dutyTypeName' => $block[0]->getDutyType()->getName(),
            'blockName' => $block[0]->getGroupInstance()?->getPattern()->getName(),
            'dates' => $dates,
            'startsAt' => $startsAt->format(\DATE_ATOM),
            'endsAt' => $endsAt->format(\DATE_ATOM),
        ];
    }

    /**
     * "la garde du lundi 10 novembre 2026" / "le bloc Week-end du vendredi 6 au dimanche 8 novembre 2026" — for emails and messages.
     *
     * @param array{dates: list<string>, blockName: string|null, dutyTypeName: string} $unit
     */
    public static function phrase(array $unit): string
    {
        $dates = array_map(static fn (string $date): \DateTimeImmutable => new \DateTimeImmutable($date), $unit['dates']);
        if (null !== $unit['blockName']) {
            return 'le bloc '.$unit['blockName'].' '.FrenchDate::range($dates[0], $dates[\count($dates) - 1]);
        }

        return 'la garde du '.FrenchDate::withWeekday($dates[0]);
    }
}
