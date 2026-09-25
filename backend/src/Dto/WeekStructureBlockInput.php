<?php

declare(strict_types=1);

namespace App\Dto;

/**
 * One block entry of a PUT .../week-structure payload (docs/decisions.md
 * D136) — at least 2 day codes, contiguous or not (§4/§15 of the spec:
 * `WeekStructureService::replace()` is the only place that actually
 * enforces the minimum, this DTO is just the deserialized shape).
 */
final class WeekStructureBlockInput
{
    /**
     * @param list<string> $days day codes, e.g. ['VEN', 'SAM', 'DIM']
     */
    public function __construct(
        public readonly string $name,
        public readonly array $days,
        public readonly string $family,
    ) {
    }
}
