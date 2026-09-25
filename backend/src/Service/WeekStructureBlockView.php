<?php

declare(strict_types=1);

namespace App\Service;

/**
 * One block entry as read back by WeekStructureService::read()
 * (docs/decisions.md D136).
 */
final readonly class WeekStructureBlockView
{
    /**
     * @param list<string> $days
     */
    public function __construct(
        public string $name,
        public array $days,
        public ?string $family,
    ) {
    }
}
