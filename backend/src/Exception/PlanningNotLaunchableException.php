<?php

declare(strict_types=1);

namespace App\Exception;

use App\Service\PlanningGenerationPreflight;

/**
 * A technical prerequisite of the generation pipeline is missing (see
 * PreflightIssueCode blockers). Raised before anything is created.
 */
final class PlanningNotLaunchableException extends \RuntimeException
{
    public function __construct(public readonly PlanningGenerationPreflight $preflight)
    {
        parent::__construct('This planning cannot be generated yet: a prerequisite of the generation is missing.');
    }
}
