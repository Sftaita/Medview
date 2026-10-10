<?php

declare(strict_types=1);

namespace App\Exception;

use App\Service\SurgicalHub\SurgicalHubFreshnessReport;

/**
 * A generation cannot start: for some associated participants, SurgicalHub
 * leave could not be refreshed and the last success is too old (D8). Only
 * the planning's creator may launch anyway, explicitly.
 */
final class SurgicalHubDataStaleException extends \RuntimeException
{
    public function __construct(
        public readonly SurgicalHubFreshnessReport $report,
        public readonly bool $overrideRefused = false,
    ) {
        parent::__construct($overrideRefused
            ? 'Only the creator of this planning can launch with outdated SurgicalHub leave.'
            : 'The SurgicalHub leave of some participants could not be refreshed and is outdated.');
    }
}
