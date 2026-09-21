<?php

declare(strict_types=1);

namespace App\Dto;

/**
 * Input shape for PATCH /api/availability-collections/{stableId}: the
 * deadline is the only mutable field ("YYYY-MM-DD", or empty to clear it).
 */
final class UpdateAvailabilityCollectionRequest
{
    public string $deadline = '';
}
