<?php

declare(strict_types=1);

namespace App\Service;

/**
 * A PlanningLine's current weekly structure (docs/decisions.md D136) —
 * built purely from its team's active DutyPatterns, never a second stored
 * representation that could drift from them.
 */
final readonly class WeekStructureView
{
    /**
     * @param list<WeekStructureBlockView> $blocks
     * @param list<string>                 $solo
     * @param list<string>                 $excluded
     */
    public function __construct(
        public array $blocks,
        public array $solo,
        public ?string $soloFamily,
        public array $excluded,
    ) {
    }
}
