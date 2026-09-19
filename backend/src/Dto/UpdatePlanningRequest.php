<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Input shape for PATCH /api/plannings/{stableId}. Only $name is
 * updatable in v1 — see Planning::rename() for why $startsAt/$endsAt are
 * deliberately not here.
 */
final class UpdatePlanningRequest
{
    #[Assert\NotBlank]
    public string $name = '';
}
