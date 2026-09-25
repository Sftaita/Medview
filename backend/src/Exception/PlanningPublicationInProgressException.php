<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * A second concurrent POST /publish for the same Planning is refused
 * outright (docs/decisions.md D133 §Concurrence) — same
 * `pg_try_advisory_lock` pattern as `PlanningGenerationLauncher` (D129),
 * a different namespace. Only one request ever actually transitions the
 * lifecycle; the other gets this, never a silent double-transition.
 */
final class PlanningPublicationInProgressException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('A publication of this Planning is already in progress.');
    }
}
