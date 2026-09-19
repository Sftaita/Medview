<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Input shape for POST /api/plannings/{stableId}/lines. Always creates a
 * SECONDARY line together with a brand new PlanningTeam named $name — the
 * PRIMARY one is only ever created alongside the Planning itself
 * (PlanningService::create()), never accepted from a client-chosen "type"
 * field. Notably absent: any team identifier — a client can never
 * reference an existing team (docs/decisions.md D079).
 */
final class CreatePlanningLineRequest
{
    #[Assert\NotBlank]
    public string $name = '';
}
