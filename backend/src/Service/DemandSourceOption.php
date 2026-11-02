<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\PlanningLine;
use App\Entity\User;

/**
 * A line that may be chosen as source (docs/decisions.md D162): another
 * active, INDEPENDENT line of the same Planning — with the people who
 * currently belong to it (one entry per person, however many stints).
 */
final readonly class DemandSourceOption
{
    /**
     * @param list<User> $people sorted by name
     */
    public function __construct(
        public PlanningLine $line,
        public array $people,
        /** @var array{blocks: list<array{name: string, weekdays: list<string>}>, soloWeekdays: list<string>, excludedWeekdays: list<string>} docs/decisions.md D224 */
        public array $structure = ['blocks' => [], 'soloWeekdays' => [], 'excludedWeekdays' => []],
    ) {
    }
}
