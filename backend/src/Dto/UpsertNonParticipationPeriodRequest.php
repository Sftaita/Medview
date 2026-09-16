<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Input shape for the administrative non-participation endpoints. See
 * UpsertUserAvailabilityPeriodRequest for why dates stay raw strings here.
 */
final class UpsertNonParticipationPeriodRequest
{
    #[Assert\NotBlank]
    public string $startsAt = '';

    #[Assert\NotBlank]
    public string $endsAt = '';
}
