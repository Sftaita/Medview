<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * The window of an availability collection does not lie inside a single
 * period of its Planning (docs/planning-cycles.md I12, §5.9): a collection
 * belongs to exactly one period.
 */
final class AvailabilityCollectionOutsidePeriodException extends \RuntimeException implements ApiProblem
{
    public function __construct()
    {
        parent::__construct('The window of a collection must lie inside a single period of the planning.');
    }

    public function statusCode(): int
    {
        return 422;
    }

    public function errorCode(): string
    {
        return 'window_outside_period';
    }
}
