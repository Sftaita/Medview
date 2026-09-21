<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Input shape for POST /api/plannings/{planningStableId}/availability-collections:
 * an explicit collection over [startsAt, endsAt) (endsAt exclusive, like the
 * Planning itself). Dates are plain "YYYY-MM-DD" strings, parsed strictly by
 * the controller.
 */
final class CreateAvailabilityCollectionRequest
{
    #[Assert\NotBlank]
    public string $startsAt = '';

    #[Assert\NotBlank]
    public string $endsAt = '';

    /** Optional "YYYY-MM-DD"; empty = no deadline. */
    public string $deadline = '';
}
