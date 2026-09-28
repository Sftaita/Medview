<?php

declare(strict_types=1);

namespace App\Service;

/**
 * A day of a conditional line that could not be materialized because its
 * coverage source is not uniquely determined (docs/decisions.md D163) —
 * reported, never resolved by guessing:
 *
 * - MISSING: the source line has no duty that calendar day (no
 *   reinforcement can exist then; for a block, the whole occurrence is
 *   skipped — a block is atomic);
 * - AMBIGUOUS: the source line has several duties that day.
 *
 * The day stays unmaterialized, so it is examined again (and reported
 * again) at the next preflight — e.g. once the source line's own calendar
 * exists.
 */
final readonly class CoverageSourceAnomaly
{
    public const MISSING = 'MISSING';
    public const AMBIGUOUS = 'AMBIGUOUS';

    public function __construct(
        public \DateTimeImmutable $localDate,
        public string $kind,
    ) {
    }
}
