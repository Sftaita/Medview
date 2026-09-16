<?php

declare(strict_types=1);

namespace App\Dto;

use App\Entity\UserAvailabilityType;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Input shape for POST/PATCH /api/me/calendar. Deliberately carries no
 * $reason field (docs/availability.md). $startsAt/$endsAt stay raw strings
 * here — parsing them into DateTimeImmutable and checking their ordering
 * is the controller's job, so a malformed date reports as one more
 * validation_failed violation rather than a different error shape.
 */
final class UpsertUserAvailabilityPeriodRequest
{
    #[Assert\NotBlank]
    #[Assert\Choice(choices: ['UNAVAILABLE', 'PREFER_DUTY'])]
    public string $type = '';

    #[Assert\NotBlank]
    public string $startsAt = '';

    #[Assert\NotBlank]
    public string $endsAt = '';

    public function typeEnum(): UserAvailabilityType
    {
        return UserAvailabilityType::from($this->type);
    }
}
