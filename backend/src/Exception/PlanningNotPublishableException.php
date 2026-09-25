<?php

declare(strict_types=1);

namespace App\Exception;

use App\Service\PublicationPreflight;

/**
 * The current calendar is not publishable (docs/decisions.md D133) — the
 * server always re-runs the real preflight at POST /publish time, never
 * trusting a GET the frontend loaded moments earlier (§11 of the spec).
 * Carries the real preflight so the controller can return exactly why,
 * never a generic refusal.
 */
final class PlanningNotPublishableException extends \RuntimeException
{
    public function __construct(
        public readonly PublicationPreflight $preflight,
    ) {
        parent::__construct('This Planning\'s current calendar is not publishable.');
    }
}
