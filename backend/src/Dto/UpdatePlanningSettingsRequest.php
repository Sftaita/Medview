<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Input shape for PATCH /api/plannings/{stableId}/settings. The only
 * setting today is the informative availability deadline ("YYYY-MM-DD", or
 * null to clear it). The key must be present: an empty body is refused
 * rather than read as "clear the deadline" (the controller checks that).
 */
final class UpdatePlanningSettingsRequest
{
    #[Assert\Date]
    public ?string $availabilityDeadline = null;
}
