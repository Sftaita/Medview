<?php

declare(strict_types=1);

namespace App\Dto;

/**
 * Input shape for PUT .../planning-lines/{id}/week-structure
 * (docs/decisions.md D136) — always the *complete* weekly structure,
 * replaced atomically, never a partial patch (§14 of the spec: "une mise à
 * jour atomique de la structure complète de la semaine plutôt qu'une
 * succession d'appels").
 *
 * $soloFamily is deliberately one shared family for every solo day of the
 * line, not one family per individual solo day (docs/decisions.md D136 —
 * every example in the spec that uses solo days groups them into a single
 * family, e.g. "L/Ma/Me/Je = WEEKDAY"; letting two solo days disagree on
 * family is a real future extension, not built here without a demonstrated
 * need). An empty string means "no family for solo days" — WeekStructureService
 * never invents one.
 */
final class WeekStructureUpdateRequest
{
    /**
     * @param list<WeekStructureBlockInput> $blocks
     * @param list<string>                  $solo     day codes with no block
     * @param list<string>                  $excluded day codes with no duty at all
     */
    public function __construct(
        public readonly array $blocks,
        public readonly array $solo,
        public readonly string $soloFamily,
        public readonly array $excluded,
    ) {
    }
}
