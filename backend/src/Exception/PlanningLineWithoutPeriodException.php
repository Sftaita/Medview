<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * The line was added while no period of its Planning could take it
 * (docs/planning-cycles.md §5.5): it has no PlanningPeriod yet, so nothing
 * can be generated, materialized or read for it until the next period is
 * created.
 */
final class PlanningLineWithoutPeriodException extends \RuntimeException implements ApiProblem
{
    public function __construct()
    {
        parent::__construct('This line has no period yet: it takes part from the next period created.');
    }

    public function statusCode(): int
    {
        return 409;
    }

    public function errorCode(): string
    {
        return 'line_has_no_period';
    }
}
