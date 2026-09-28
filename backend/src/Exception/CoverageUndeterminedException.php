<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * A NEW assignment on a conditional duty whose live demand cannot be
 * evaluated — a source duty nobody holds (docs/decisions.md D165). Refused:
 * the source must be settled first; never treated as "required" nor as
 * "not required".
 */
final class CoverageUndeterminedException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Whether this reinforcement is required cannot be determined: its source duty has no holder.');
    }
}
