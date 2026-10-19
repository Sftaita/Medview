<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * A flow not yet made period-aware (generation, completion, results,
 * publication, reassignment, swaps, exports — lots L3/L4 of
 * docs/planning-cycles.md) was asked to work on a Planning that has several
 * periods. It refuses instead of guessing which period was meant
 * (docs/decisions.md D187).
 */
final class MultiplePeriodsNotSupportedException extends \RuntimeException implements ApiProblem
{
    public function __construct()
    {
        parent::__construct('This action is not available yet on a planning with several periods.');
    }

    public function statusCode(): int
    {
        return 409;
    }

    public function errorCode(): string
    {
        return 'multiple_periods_not_supported';
    }
}
