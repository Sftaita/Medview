<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Duty;

/**
 * One constituent Duty of the block being reassigned — a single Duty is a
 * block of one (docs/decisions.md D131). Read-only projection, never the
 * entity itself.
 */
final readonly class ReassignmentBlockDuty
{
    public string $dutyStableId;
    public string $date;
    public string $startsAt;
    public string $endsAt;
    public string $dutyTypeName;

    public function __construct(Duty $duty)
    {
        $this->dutyStableId = (string) $duty->getStableId();
        $this->date = $duty->getLocalDate()->format('Y-m-d');
        $this->startsAt = $duty->getStartsAt()->format(\DATE_ATOM);
        $this->endsAt = $duty->getEndsAt()->format(\DATE_ATOM);
        $this->dutyTypeName = $duty->getDutyType()->getName();
    }
}
