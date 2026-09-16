<?php

declare(strict_types=1);

namespace App\Exception;

use App\Entity\PlanningPeriodStatus;

final class InvalidPlanningPeriodTransitionException extends \LogicException
{
    public function __construct(
        public readonly PlanningPeriodStatus $from,
        public readonly PlanningPeriodStatus $to,
    ) {
        parent::__construct(sprintf('Cannot transition a PlanningPeriod from %s to %s.', $from->value, $to->value));
    }
}
