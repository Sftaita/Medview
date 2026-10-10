<?php

declare(strict_types=1);

namespace App\Service\SurgicalHub;

/**
 * One SurgicalHub absence as the v1 contract describes it: calendar dates,
 * $endDate inclusive. Nothing else crosses the boundary (never a reason).
 */
final readonly class SurgicalHubAbsence
{
    public const STATUS_CONFIRMED = 'CONFIRMED';

    public function __construct(
        public string $id,
        public \DateTimeImmutable $startDate,
        public \DateTimeImmutable $endDate,
        public string $status,
    ) {
    }

    public function isConfirmed(): bool
    {
        return self::STATUS_CONFIRMED === $this->status;
    }
}
