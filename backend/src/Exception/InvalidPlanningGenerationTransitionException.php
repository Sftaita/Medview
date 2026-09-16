<?php

declare(strict_types=1);

namespace App\Exception;

use App\Entity\PlanningGenerationStatus;

/**
 * Entity-level guard, mirrors InvalidPlanningPeriodTransitionException.
 * Never expected to actually surface through the API in this lot (the
 * service layer pre-checks status before calling transitionTo() — see
 * PlanningGenerationAlreadySnapshottedException for the user-facing
 * equivalent) — this exists as defense in depth, consistent with every
 * other cross-entity guard in this domain.
 */
final class InvalidPlanningGenerationTransitionException extends \LogicException
{
    public function __construct(
        public readonly PlanningGenerationStatus $from,
        public readonly PlanningGenerationStatus $to,
    ) {
        parent::__construct(sprintf('Cannot transition a PlanningGeneration from %s to %s.', $from->value, $to->value));
    }
}
