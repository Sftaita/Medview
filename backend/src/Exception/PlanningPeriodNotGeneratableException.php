<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * docs/planning-cycles.md I8 (lot L3): a VALIDATED, PUBLISHED or ARCHIVED
 * period is never regenerated — no new generation is ever created on it. It
 * stays reassignable and completable (holes only) as before.
 */
final class PlanningPeriodNotGeneratableException extends \RuntimeException implements ApiProblem
{
    public function __construct()
    {
        parent::__construct('This period is validated, published or archived: it is never generated again.');
    }

    public function statusCode(): int
    {
        return 409;
    }

    public function errorCode(): string
    {
        return 'period_locked';
    }
}
