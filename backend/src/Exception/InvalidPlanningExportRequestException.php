<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * An export request the API refuses (422 `validation_failed`), one message
 * per offending field — same shape as every other input error.
 */
final class InvalidPlanningExportRequestException extends \RuntimeException
{
    /**
     * @param array<string, string> $violations
     */
    public function __construct(public readonly array $violations)
    {
        parent::__construct('Invalid export request.');
    }
}
