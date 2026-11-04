<?php

declare(strict_types=1);

namespace App\Service;

use App\Demand\DemandReason;
use App\Demand\Weekday;
use App\Entity\Duty;

/** One day of a required post without holder (docs/decisions.md D232). */
final readonly class PublicationUncoveredDay
{
    public function __construct(
        public \DateTimeImmutable $date,
        public Weekday $weekday,
        public DemandReason $reason,
        /** Null when the reinforcement is not created yet. */
        public ?Duty $duty,
        /** Null on an independent line. */
        public ?Duty $sourceDuty,
        public ?string $sourceHolderUserStableId,
    ) {
    }
}
