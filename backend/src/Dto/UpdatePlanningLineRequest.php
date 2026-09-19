<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Input shape for PATCH /api/plannings/{stableId}/lines/{lineStableId}.
 * Only $name is updatable in v1 — no reordering/promotion/reassignment.
 */
final class UpdatePlanningLineRequest
{
    #[Assert\NotBlank]
    public string $name = '';
}
